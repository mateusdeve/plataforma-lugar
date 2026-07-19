<?php

declare(strict_types=1);

namespace Lugar\Domain\Reserva;

/**
 * Enum e não string: o compilador passa a impedir um status inventado, e o
 * `match` sobre ele avisa quando um caso novo aparece sem tratamento.
 */
enum StatusDaReserva: string
{
    case PENDENTE = 'PENDENTE';
    case CONFIRMADA = 'CONFIRMADA';
    case EXPIRADA = 'EXPIRADA';
    case CANCELADA = 'CANCELADA';

    /** Os três estados finais são terminais — nenhuma transição sai deles. */
    public function ehTerminal(): bool
    {
        return self::PENDENTE !== $this;
    }
}
