<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Notificacao;

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
    ) {
    }

    public function __invoke(EnviarConfirmacaoDeCompra $mensagem): void
    {
        $email = (new Email())
            ->from($this->remetente)
            ->to($mensagem->compradorEmail)
            ->subject('Seu ingresso — lugar.')
            ->text($this->corpo($mensagem));

        $this->mailer->send($email);
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
