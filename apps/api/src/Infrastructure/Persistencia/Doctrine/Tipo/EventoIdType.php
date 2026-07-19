<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Evento\EventoId;

/** @extends TipoDeIdentidade<EventoId> */
final class EventoIdType extends TipoDeIdentidade
{
    public const string NOME = 'evento_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return EventoId::class;
    }
}
