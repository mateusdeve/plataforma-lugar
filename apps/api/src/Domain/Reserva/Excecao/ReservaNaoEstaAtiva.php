<?php

declare(strict_types=1);

namespace Lugar\Domain\Reserva\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * RN-07. Vira 409 com type=reserva-expirada — a tela própria de expiração.
 *
 * É o SEGUNDO 409 do sistema, e o motivo de o front nunca decidir pela
 * mensagem: os dois têm o mesmo status e tratamentos completamente diferentes.
 */
final class ReservaNaoEstaAtiva extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'reserva-expirada';
    }
}
