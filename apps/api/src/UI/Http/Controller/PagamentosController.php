<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Consulta\ConsultaDeIngressos;
use Lugar\Application\Pagamento\ConfirmarPagamento;
use Lugar\Application\Pagamento\Excecao\AssinaturaInvalida;
use Lugar\Application\Pagamento\GatewayDePagamento;
use Lugar\Application\Pagamento\IniciarCheckout;
use Lugar\Application\Pagamento\WebhookDePagamento;
use Lugar\Domain\Pagamento\Excecao\PagamentoJaRegistrado;
use Lugar\Application\Pagamento\SimuladorDePagamento;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\ReservaId;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Abertura de cobrança e recebimento do webhook.
 */
final readonly class PagamentosController
{
    public function __construct(
        private IniciarCheckout $iniciar,
        private ConfirmarPagamento $confirmar,
        private WebhookDePagamento $webhook,
        private GatewayDePagamento $gateway,
        private ConsultaDeIngressos $ingressosDaReserva,
        private RepositorioDeReservas $reservas,
        private SimuladorDePagamento $simulador,
        private string $ambiente,
        private bool $simulacaoPermitida,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/reservas/{id}/checkout', name: 'pagamentos_checkout', methods: ['POST'])]
    public function checkout(string $id): JsonResponse
    {
        $cobranca = ($this->iniciar)($id);

        $resposta = new JsonResponse([
            'referencia' => $cobranca->referenciaDoProvedor,
            'urlDePagamento' => $cobranca->urlDePagamento,
            'expiraEm' => $cobranca->expiraEm->format(\DATE_ATOM),
            'provedor' => $this->gateway->nome(),
        ], Response::HTTP_CREATED);

        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * O ENDPOINT MAIS EXPOSTO DO SISTEMA.
     *
     * Público, sem sessão, sem token — e manda emitir ingresso. Está fora do
     * firewall no `security.yaml` porque quem o chama é uma máquina que não
     * tem conta aqui. A única credencial é a assinatura HMAC.
     *
     * SOBRE OS CÓDIGOS DE RESPOSTA
     *
     * Gateway trata a resposta como instrução de reentrega: 2xx é "recebi,
     * pode parar"; qualquer outra coisa é "tente de novo". Por isso:
     *
     *   assinatura inválida  → 400  não é o provedor, e reenviar não conserta
     *   já processado        → 200  a entrega funcionou, o efeito já existe
     *   processado agora     → 200
     *   erro nosso           → 500  aí SIM queremos que ele reenvie
     *
     * Devolver 500 na reentrega — o erro fácil — faz o provedor reenviar em
     * backoff exponencial contra um endpoint que está funcionando.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Route('/api/webhooks/pagamento', name: 'pagamentos_webhook', methods: ['POST'])]
    public function receber(Request $request): JsonResponse
    {
        /*
          `getContent()` devolve o corpo BRUTO, byte a byte. É obrigatório que
          seja assim: o HMAC foi calculado sobre esses bytes, e qualquer
          decodificação-recodificação no caminho invalidaria a conferência.
        */
        try {
            $aviso = $this->webhook->verificarEInterpretar(
                $request->getContent(),
                $this->cabecalhos($request),
            );
        } catch (AssinaturaInvalida $erro) {
            // O motivo vai para o log; a resposta não diz o que falhou, para
            // não ensinar quem está tentando forjar.
            $this->logger->warning('Webhook de pagamento recusado.', [
                'motivo' => $erro->getMessage(),
                'ip' => $request->getClientIp(),
            ]);

            return new JsonResponse(['erro' => 'assinatura inválida'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $ingressos = ($this->confirmar)($aviso);
        } catch (PagamentoJaRegistrado $erro) {
            $this->logger->info('Webhook reentregue; nada a fazer.', [
                'provedor_id' => $erro->provedorId,
            ]);

            return new JsonResponse(['status' => 'ja-processado'], Response::HTTP_OK);
        }

        return new JsonResponse([
            'status' => 'processado',
            'ingressosEmitidos' => \count($ingressos),
        ], Response::HTTP_OK);
    }

    /**
     * Os ingressos emitidos de uma reserva — a tela de confirmação (PLAN 5.7).
     *
     * Sem autenticação, igual ao `GET /api/reservas/{id}` que já existia: o
     * checkout de convidado não tem conta (ADR-004), então não há em quem
     * confiar além de quem conhece o id. O id é UUID v7, aleatório o bastante
     * para não ser adivinhado, e funciona como capacidade — o mesmo modelo de
     * um link "não listado".
     *
     * É uma escolha de MVP, não o ideal: quem receber o link encaminhado vê os
     * códigos. Amarrar à conta quando ela existir é trabalho de quando o
     * checkout de convidado deixar de ser o caminho principal.
     */
    #[Route('/api/reservas/{id}/ingressos', name: 'pagamentos_ingressos', methods: ['GET'])]
    public function ingressos(string $id): JsonResponse
    {
        $resposta = new JsonResponse(['itens' => $this->ingressosDaReserva->daReserva($id)]);

        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * SIMULAÇÃO DE PAGAMENTO — FECHADA POR PADRÃO FORA DE DEV/TEST.
     *
     * Sem gateway real configurado, nada dispara o webhook localmente e o
     * fluxo morre no checkout. Esta rota faz o papel do provedor: monta a
     * MESMA requisição assinada e a manda pela MESMA verificação HMAC.
     *
     * Num produto com dinheiro de verdade, esta rota em produção seria
     * ingresso de graça para quem descobrisse a URL — por isso o padrão é
     * falhar fechado, pelo ambiente. A exceção é explícita e única: ESTA
     * produção é uma vitrine de demonstração, o gateway é o de demonstração
     * e não existe valor a desviar. `PAGAMENTO_SIMULADO=true` (definida no
     * painel, nunca no repositório) liga a rota lá — e sai no dia em que um
     * gateway real entrar, junto com a troca no services.yaml.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Route('/api/reservas/{id}/simular-pagamento', name: 'pagamentos_simular', methods: ['POST'])]
    public function simular(string $id, Request $request): JsonResponse
    {
        if (!$this->simulacaoPermitida && !\in_array($this->ambiente, ['dev', 'test'], true)) {
            throw new NotFoundHttpException();
        }

        $reserva = $this->reservas->buscar(new ReservaId($id))
            ?? throw new NotFoundHttpException();

        $aprovado = false !== $request->query->get('aprovado', '1');

        $simulado = $this->simulador->webhookPara($reserva, $aprovado);

        // Passa pela verificação de assinatura como qualquer webhook. Chamar
        // ConfirmarPagamento direto daqui seria mais curto e deixaria o
        // caminho de produção sem exercício nenhum em desenvolvimento.
        $aviso = $this->webhook->verificarEInterpretar($simulado->corpo, $simulado->cabecalhos);

        try {
            $ingressos = ($this->confirmar)($aviso);
        } catch (PagamentoJaRegistrado) {
            return new JsonResponse(['status' => 'ja-processado'], Response::HTTP_OK);
        }

        return new JsonResponse([
            'status' => $aprovado ? 'processado' : 'recusado',
            'ingressosEmitidos' => \count($ingressos),
        ], Response::HTTP_OK);
    }

    /**
     * @return array<string, string>
     */
    private function cabecalhos(Request $request): array
    {
        $saida = [];

        foreach ($request->headers->all() as $nome => $valores) {
            $primeiro = $valores[0] ?? null;

            if (\is_string($primeiro)) {
                $saida[$nome] = $primeiro;
            }
        }

        return $saida;
    }
}
