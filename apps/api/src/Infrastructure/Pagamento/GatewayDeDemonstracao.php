<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Pagamento;

use Lugar\Application\Pagamento\CobrancaAberta;
use Lugar\Application\Pagamento\GatewayDePagamento;
use Lugar\Application\Pagamento\SimuladorDePagamento;
use Lugar\Application\Pagamento\WebhookSimulado;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Reserva\Reserva;
use Symfony\Component\Uid\Uuid;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O GATEWAY QUE RODA SEM CHAVE NENHUMA.
 *
 * O PRD §14 pede que quem clonar o repositório consiga rodar tudo com um
 * `docker compose up`. Um gateway real quebra isso: exige conta, chave de API e
 * um túnel para o webhook voltar. A pessoa desiste antes de ver o produto.
 *
 * Este adaptador fecha o circuito localmente. Ele NÃO é um mock no sentido de
 * "finge que funciona": a cobrança que ele abre é registrada, e a confirmação
 * chega pelo mesmo endpoint de webhook, com a MESMA assinatura HMAC que o
 * provedor real usaria. O caminho exercitado em desenvolvimento é o caminho de
 * produção, menos a chamada HTTP para fora.
 *
 * O QUE ELE DELIBERADAMENTE NÃO FAZ
 *
 * Não confirma nada sozinho. Quem dispara o webhook é a tela de pagamento
 * chamando a rota de simulação — porque em produção quem dispara é o provedor,
 * depois que o dinheiro entrou. Um adaptador que confirmasse na hora de abrir a
 * cobrança esconderia justamente a assincronia que causa os bugs difíceis.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class GatewayDeDemonstracao implements GatewayDePagamento, SimuladorDePagamento
{
    public function __construct(
        private Relogio $relogio,
        private string $urlDoFront,
        private string $segredoDoWebhook,
    ) {
    }

    public function abrirCobranca(Reserva $reserva): CobrancaAberta
    {
        $referencia = sprintf('demo_%s', Uuid::v7());

        return new CobrancaAberta(
            referenciaDoProvedor: $referencia,
            // Leva de volta ao próprio checkout, que é onde a simulação roda.
            urlDePagamento: sprintf('%s/checkout/%s', rtrim($this->urlDoFront, '/'), $reserva->id->valor),
            // Espelha o prazo da reserva: cobrança que sobrevive à reserva
            // permitiria pagar por um lugar já devolvido ao estoque.
            expiraEm: $reserva->expiraEm,
        );
    }

    public function nome(): string
    {
        return 'Demonstração';
    }

    /**
     * Monta a requisição completa — corpo E assinatura — que o provedor real
     * enviaria. Quem a recebe é a mesma verificação HMAC de produção; o que
     * muda é só quem apertou o botão.
     */
    public function webhookPara(Reserva $reserva, bool $aprovado): WebhookSimulado
    {
        $corpo = $this->corpoDoWebhook($reserva, $aprovado);

        return new WebhookSimulado(
            corpo: $corpo,
            cabecalhos: [
                AssinaturaHmac::CABECALHO => AssinaturaHmac::assinar(
                    $corpo,
                    $this->segredoDoWebhook,
                    $this->relogio->agora()->getTimestamp(),
                ),
            ],
        );
    }

    private function corpoDoWebhook(Reserva $reserva, bool $aprovado): string
    {
        return json_encode([
            'id' => sprintf('evt_%s', Uuid::v7()),
            'reserva_id' => $reserva->id->valor,
            'valor_centavos' => $reserva->total->centavos,
            'status' => $aprovado ? 'aprovado' : 'recusado',
            'ocorrido_em' => $this->relogio->agora()->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
    }
}
