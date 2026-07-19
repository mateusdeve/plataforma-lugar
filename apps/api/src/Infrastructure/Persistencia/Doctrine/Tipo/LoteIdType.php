<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Lote\LoteId;

/** @extends TipoDeIdentidade<LoteId> */
final class LoteIdType extends TipoDeIdentidade
{
    public const string NOME = 'lote_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return LoteId::class;
    }
}
