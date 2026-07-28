<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Application\Comum\Transacao;
use Lugar\Application\Notificacao\Notificador;
use Lugar\Application\Pagamento\Excecao\ValorNaoConfere;
use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\RepositorioDeIngressos;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Pagamento\Excecao\PagamentoJaRegistrado;
use Lugar\Domain\Pagamento\Pagamento;
use Lugar\Domain\Pagamento\RepositorioDePagamentos;
use Lugar\Domain\Pagamento\StatusDoPagamento;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O DINHEIRO CHEGOU — VIRAR RESERVA EM INGRESSO.
 *
 * Critério de pronto da fase 5 (PLAN.md): "reprocessar o mesmo webhook três
 * vezes não duplica ingresso, e existe teste provando isso".
 *
 * TRÊS COISAS ACONTECEM JUNTAS, OU NENHUMA ACONTECE
 *
 *   1. o pagamento é registrado
 *   2. o lote passa o estoque de RESERVADO para VENDIDO
 *   3. a reserva vira CONFIRMADA e nascem os ingressos (RN-08)
 *
 * O passo 2 é o que quase se esquece, e o esquecimento é grave. A query do
 * ADR-002 conta como reservado quem está `PENDENTE`. No instante em que a
 * reserva vira `CONFIRMADA` ela sai dessa conta — e se `quantidade_vendida`
 * não subir na MESMA transação, os lugares vendidos voltam a aparecer como
 * disponíveis. O sistema revenderia o que acabou de vender.
 *
 * IDEMPOTÊNCIA
 *
 * Gateway reenvia webhook: por timeout, por retentativa, por política de "pelo
 * menos uma entrega". A consulta por `provedorId` no começo resolve o caso
 * comum; o UNIQUE do banco resolve o caso que importa, que é o de duas
 * entregas simultâneas passando pela consulta juntas. Uma grava, a outra leva
 * violação de unicidade e vira `PagamentoJaRegistrado` — que o controller
 * traduz em 200, porque para o provedor está tudo certo.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class ConfirmarPagamento
{
    public function __construct(
        private Transacao $transacao,
        private RepositorioDePagamentos $pagamentos,
        private RepositorioDeReservas $reservas,
        private RepositorioDeLotes $lotes,
        private RepositorioDeIngressos $ingressos,
        private Notificador $notificador,
        private GeradorDeIdentidade $gerador,
        private Relogio $relogio,
    ) {
    }

    /**
     * @return list<Ingresso> os ingressos emitidos — vazio quando o pagamento
     *                        foi recusado
     *
     * @throws PagamentoJaRegistrado o webhook é reentrega; nada a fazer
     * @throws ValorNaoConfere       o valor pago não quita a reserva
     */
    public function __invoke(AvisoDePagamento $aviso): array
    {
        // Caminho rápido da reentrega. Não é a garantia — é a economia de
        // trabalho no caso comum, que é o gateway reenviando por precaução.
        if (null !== $this->pagamentos->buscarPorProvedorId($aviso->provedorId)) {
            throw new PagamentoJaRegistrado($aviso->provedorId);
        }

        return $this->transacao->executar(function () use ($aviso): array {
            $agora = $this->relogio->agora();

            /*
              ═══════════════════════════════════════════════════════════════
              O LOCK VEM PRIMEIRO, E NADA ATRAVESSA ELE.

              `buscarParaAtualizacao()` chama `EntityManager::clear()` antes do
              SELECT ... FOR UPDATE — sem isso o Doctrine devolveria a cópia do
              Identity Map e não iria ao banco, ou seja, não travaria nada. A
              razão está documentada no repositório e é correta.

              O efeito colateral é que o `clear()` DESANEXA TODAS as entidades,
              não só o lote. Uma reserva carregada antes desta linha vira
              detached; `persist()` numa detached com id atribuído não atualiza
              — agenda um INSERT, que colide com a chave primária.

              Foi exatamente o que aconteceu na primeira versão deste arquivo.
              O erro que aparece é "duplicate key value violates reserva_pkey"
              ao confirmar, três camadas longe da causa.

              Por isso: trava primeiro, carrega depois. A leitura inicial da
              reserva serve só para descobrir QUAL lote travar, e o objeto que
              ela devolve é descartado de propósito.
              ═══════════════════════════════════════════════════════════════
            */
            $loteId = $this->exigirReserva($aviso->reservaId)->loteId;
            $lote = $this->lotes->buscarParaAtualizacao($loteId)
                ?? throw new \RuntimeException(sprintf('Lote %s não existe.', $loteId));

            // Recarregada DEPOIS do clear — esta sim está gerenciada.
            $reserva = $this->exigirReserva($aviso->reservaId);

            $pagamento = Pagamento::registrar(
                $this->gerador->novoPagamentoId(),
                $reserva->id,
                $aviso->provedorId,
                $aviso->valor,
                $aviso->aprovado ? StatusDoPagamento::APROVADO : StatusDoPagamento::RECUSADO,
                $aviso->payloadBruto,
                $agora,
            );

            // Grava ANTES de confirmar: é este INSERT que colide no UNIQUE se
            // outro webhook idêntico estiver no meio do caminho. Colidir antes
            // de emitir ingresso é a diferença entre uma exceção e uma leva de
            // ingressos duplicados.
            $this->pagamentos->salvar($pagamento);

            if (!$pagamento->foiAprovado()) {
                // Recusa também é informação, e fica registrada. A reserva
                // segue PENDENTE e expira sozinha se ninguém pagar.
                return [];
            }

            /*
              O valor vem de fora, numa requisição HTTP. A assinatura prova a
              ORIGEM, não o conteúdo estar correto para o nosso negócio —
              confirmar uma reserva de R$ 220 com um pagamento de R$ 2 passaria
              por qualquer verificação criptográfica.
            */
            if (!$pagamento->quitaIntegralmente($reserva->total)) {
                throw new ValorNaoConfere($reserva->total->centavos, $aviso->valor->centavos);
            }

            /*
              O estoque sai de RESERVADO e vira VENDIDO.

              O lock adquirido lá em cima é o que torna isto seguro:
              `registrarVenda` faz leitura-modificação-escrita de
              `quantidade_vendida`, e duas confirmações simultâneas no mesmo
              lote leriam o mesmo valor, somariam sobre ele, e uma das vendas
              sumiria — sem o CHECK do banco reclamar, porque o total continua
              abaixo do limite.
            */
            $lote->registrarVenda($reserva->quantidade);
            $this->lotes->salvar($lote);

            // RN-07: confirmar exige reserva ativa. Um pagamento que chega
            // depois do prazo estoura aqui com type=reserva-expirada.
            $reserva->confirmar($agora);
            $this->reservas->salvar($reserva);

            $emitidos = $this->emitirIngressos($reserva, $agora);

            /*
              Ainda DENTRO da transação, e de propósito: o transporte é o
              próprio banco, então despachar é um INSERT que commita junto com
              a venda. Rollback leva a mensagem embora; commit garante que ela
              existe. Ver o comentário longo em NotificadorPorMensageria.
            */
            $this->notificador->confirmacaoDeCompra($reserva, $emitidos);

            return $emitidos;
        });
    }

    /**
     * RN-08: um ingresso por unidade da reserva.
     *
     * Quatro ingressos numa compra de quatro, e não um ingresso "com
     * quantidade 4". Quem comprou para o grupo manda um código para cada
     * pessoa, e cada um entra pela porta no seu horário.
     *
     * @return list<Ingresso>
     */
    private function emitirIngressos(Reserva $reserva, \DateTimeImmutable $agora): array
    {
        $emitidos = [];

        for ($i = 0; $i < $reserva->quantidade; ++$i) {
            $ingresso = Ingresso::emitir(
                $this->gerador->novoIngressoId(),
                $reserva->id,
                $this->gerador->novoCodigoDeIngresso(),
                $agora,
            );

            $this->ingressos->salvar($ingresso);

            $emitidos[] = $ingresso;
        }

        return $emitidos;
    }

    private function exigirReserva(string $id): Reserva
    {
        return $this->reservas->buscar(new ReservaId($id))
            ?? throw new \RuntimeException(sprintf('Reserva %s não existe.', $id));
    }
}
