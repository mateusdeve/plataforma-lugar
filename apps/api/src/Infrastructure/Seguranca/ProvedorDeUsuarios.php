<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Recarrega o usuário a cada requisição a partir do e-mail que veio no JWT.
 *
 * Isso é deliberado e custa uma consulta: os papéis vêm do BANCO, não do
 * token. Se um organizador for rebaixado, a mudança vale na próxima
 * requisição — e não só quando o token expirar.
 *
 * @implements UserProviderInterface<UsuarioAutenticado>
 */
final readonly class ProvedorDeUsuarios implements UserProviderInterface
{
    public function __construct(private RepositorioDeUsuarios $usuarios)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $usuario = $this->usuarios->buscarPorEmail($identifier);

        if (null === $usuario) {
            throw new UserNotFoundException();
        }

        return new UsuarioAutenticado($usuario);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return UsuarioAutenticado::class === $class;
    }
}
