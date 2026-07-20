<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Pagamento\PagamentoId;

/** @extends TipoDeIdentidade<PagamentoId> */
final class PagamentoIdType extends TipoDeIdentidade
{
    public const string NOME = 'pagamento_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return PagamentoId::class;
    }
}
