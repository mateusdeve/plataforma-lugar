<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Ingresso\IngressoId;

/** @extends TipoDeIdentidade<IngressoId> */
final class IngressoIdType extends TipoDeIdentidade
{
    public const string NOME = 'ingresso_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return IngressoId::class;
    }
}
