<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Domain\Usuario\Usuario;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A ponte entre o domínio e o componente de segurança do Symfony.
 *
 * O `Usuario` do domínio não pode implementar `UserInterface` — isso o faria
 * importar Symfony, e o Deptrac quebraria o build. Então este adaptador
 * envolve o objeto de domínio e satisfaz o contrato do framework.
 *
 * Custo: uma classe de 40 linhas. Retorno: as regras de quem-pode-o-quê são
 * testáveis sem container, sem kernel e sem banco.
 */
final class UsuarioAutenticado implements UserInterface
{
    public function __construct(public readonly Usuario $usuario)
    {
    }

    public function getUserIdentifier(): string
    {
        $email = $this->usuario->email;

        // O domínio já garante isto no cadastro (FILTER_VALIDATE_EMAIL), mas
        // o contrato do Symfony pede non-empty-string e a análise estática
        // não consegue provar sozinha.
        \assert('' !== $email);

        return $email;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getRoles(): array
    {
        $papeis = $this->usuario->papeisComoTexto();

        // Todo usuário tem ao menos ROLE_COMPRADOR (garantido no cadastro),
        // mas o contrato do Symfony exige a garantia no tipo.
        return [] === $papeis ? ['ROLE_COMPRADOR'] : $papeis;
    }

    /**
     * A senha não vive no token nem na sessão. Nada a apagar.
     */
    public function eraseCredentials(): void
    {
    }
}
