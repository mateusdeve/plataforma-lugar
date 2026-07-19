<?php

declare(strict_types=1);

namespace Lugar\Domain\Lote\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/** RN-06. */
final class ForaDaJanelaDeVenda extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'fora-da-janela-de-venda';
    }
}
