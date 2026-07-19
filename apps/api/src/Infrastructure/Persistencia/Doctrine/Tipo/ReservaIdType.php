<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Reserva\ReservaId;

/** @extends TipoDeIdentidade<ReservaId> */
final class ReservaIdType extends TipoDeIdentidade
{
    public const string NOME = 'reserva_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return ReservaId::class;
    }
}
