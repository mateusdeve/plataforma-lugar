<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Notificacao;

use Lugar\Infrastructure\Observabilidade\ContextoDeCorrelacao;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

/**
 * Manda o e-mail com os códigos de entrada.
 *
 * Roda no worker (`docker compose` sobe um), fora da requisição. É o ponto do
 * PLAN 5.6: se o SMTP estiver fora do ar, quem espera é a fila — não a pessoa
 * que acabou de pagar. O Messenger tenta 3 vezes com espera crescente
 * (messenger.yaml) antes de mandar para a fila de falhas.
 */
#[AsMessageHandler]
final readonly class EnviarConfirmacaoDeCompraHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private string $remetente,
        private ContextoDeCorrelacao $correlacao,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnviarConfirmacaoDeCompra $mensagem): void
    {
        // Fase 8.1: o worker retoma o id que veio DENTRO da mensagem, e todo
        // log daqui em diante — inclusive os do Mailer e de um eventual retry
        // — sai com o mesmo correlation_id da requisição que originou a compra.
        if (null !== $mensagem->correlationId) {
            $this->correlacao->definir($mensagem->correlationId);
        }

        $email = (new Email())
            ->from($this->remetente)
            ->to($mensagem->compradorEmail)
            ->subject('Seu ingresso — lugar.')
            ->text($this->corpo($mensagem));

        $this->mailer->send($email);

        $this->logger->info('E-mail de confirmação enviado.', [
            'reservaId' => $mensagem->reservaId,
            'ingressos' => \count($mensagem->codigos),
        ]);
    }

    private function corpo(EnviarConfirmacaoDeCompra $mensagem): string
    {
        $linhas = [
            'Pagamento confirmado. Seus ingressos estão emitidos.',
            '',
            // Um código por unidade (RN-08): quem comprou para o grupo repassa
            // um para cada pessoa, e cada uma entra no seu horário.
            1 === \count($mensagem->codigos) ? 'Seu código:' : 'Seus códigos:',
        ];

        foreach ($mensagem->codigos as $codigo) {
            $linhas[] = '  '.$codigo;
        }

        $linhas[] = '';
        $linhas[] = sprintf('Total pago: R$ %s', number_format($mensagem->totalCentavos / 100, 2, ',', '.'));
        $linhas[] = '';
        $linhas[] = 'Apresente o código na entrada. Ele vale uma entrada só.';

        return implode("\n", $linhas);
    }
}
