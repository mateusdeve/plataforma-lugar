<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

final class EmailJaCadastrado extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'email-ja-cadastrado';
    }
}
