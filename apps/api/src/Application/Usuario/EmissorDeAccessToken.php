<?php

declare(strict_types=1);

namespace Lugar\Application\Usuario;

use Lugar\Domain\Usuario\Usuario;

/**
 * Porta de emissão do access token.
 *
 * O caso de uso emite um token sem saber que ele é um JWT assinado com RS256
 * por um bundle específico. Application/ não pode importar Symfony nem Lexik.
 */
interface EmissorDeAccessToken
{
    public function para(Usuario $usuario): string;
}
