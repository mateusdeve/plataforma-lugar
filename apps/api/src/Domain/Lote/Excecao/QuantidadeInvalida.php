<?php

declare(strict_types=1);

namespace Lugar\Domain\Lote\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/** RN-04 e validações de quantidade. */
final class QuantidadeInvalida extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'quantidade-invalida';
    }
}
