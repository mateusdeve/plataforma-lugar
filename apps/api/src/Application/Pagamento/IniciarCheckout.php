<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Reserva\Excecao\ReservaNaoEstaAtiva;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\ReservaId;

/**
 * Abre a cobrança de uma reserva.
 *
 * A verificação de que a reserva ainda está ativa acontece aqui, e de novo lá
 * na confirmação. Não é redundância inútil: entre abrir a cobrança e o dinheiro
 * chegar passam minutos, e a reserva pode vencer no meio. Recusar já na
 * abertura evita cobrar alguém por um lugar que ele vai perder.
 */
final readonly class IniciarCheckout
{
    public function __construct(
        private RepositorioDeReservas $reservas,
        private GatewayDePagamento $gateway,
        private Relogio $relogio,
    ) {
    }

    /**
     * @throws ReservaNaoEstaAtiva 409 type=reserva-expirada — a tela 06
     */
    public function __invoke(string $reservaId): CobrancaAberta
    {
        $reserva = $this->reservas->buscar(new ReservaId($reservaId))
            ?? throw new \RuntimeException(sprintf('Reserva %s não existe.', $reservaId));

        if (!$reserva->estaAtiva($this->relogio->agora())) {
            throw new ReservaNaoEstaAtiva('O prazo desta reserva venceu.');
        }

        return $this->gateway->abrirCobranca($reserva);
    }
}
