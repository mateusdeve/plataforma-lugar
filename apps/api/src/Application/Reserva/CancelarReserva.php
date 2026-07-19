<?php

declare(strict_types=1);

namespace Lugar\Application\Reserva;

use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;

/**
 * "Desistir e liberar meu lugar".
 *
 * Não precisa de lock: cancelar não disputa estoque, DEVOLVE. E a devolução é
 * imediata sem nenhuma escrita no lote — assim que o status deixa de ser
 * PENDENTE, a query de disponibilidade (ADR-002) para de contar esta reserva.
 */
final readonly class CancelarReserva
{
    public function __construct(
        private RepositorioDeReservas $reservas,
        private Relogio $relogio,
    ) {
    }

    public function __invoke(string $reservaId): Reserva
    {
        $reserva = $this->reservas->buscar(new ReservaId($reservaId))
            ?? throw new \RuntimeException('Reserva não encontrada.');

        $reserva->cancelar($this->relogio->agora());
        $this->reservas->salvar($reserva);

        return $reserva;
    }
}
