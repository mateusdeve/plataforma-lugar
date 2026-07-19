<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso;

use Lugar\Domain\Reserva\ReservaId;

interface RepositorioDeIngressos
{
    public function buscarPorCodigo(CodigoIngresso $codigo): ?Ingresso;

    public function salvar(Ingresso $ingresso): void;

    /** @return list<Ingresso> */
    public function daReserva(ReservaId $reservaId): array;
}
