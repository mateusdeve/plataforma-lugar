<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Application\Usuario\UsuarioAtual;
use Lugar\Domain\Usuario\Usuario;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class UsuarioAtualDoSymfony implements UsuarioAtual
{
    public function __construct(private Security $security)
    {
    }

    public function usuario(): ?Usuario
    {
        $autenticado = $this->security->getUser();

        return $autenticado instanceof UsuarioAutenticado ? $autenticado->usuario : null;
    }
}
