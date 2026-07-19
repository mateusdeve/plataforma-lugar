<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lugar\Application\Usuario\EmissorDeAccessToken;
use Lugar\Domain\Usuario\Usuario;

final readonly class EmissorJwt implements EmissorDeAccessToken
{
    public function __construct(private JWTTokenManagerInterface $gerenciador)
    {
    }

    public function para(Usuario $usuario): string
    {
        return $this->gerenciador->create(new UsuarioAutenticado($usuario));
    }
}
