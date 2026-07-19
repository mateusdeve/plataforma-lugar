<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Usuario\RepositorioDeTokens;
use Lugar\Domain\Usuario\TokenDeRenovacao;
use Lugar\Domain\Usuario\UsuarioId;

final readonly class RepositorioDoctrineDeTokens implements RepositorioDeTokens
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscarPorHash(string $hash): ?TokenDeRenovacao
    {
        return $this->em->find(TokenDeRenovacao::class, $hash);
    }

    public function salvar(TokenDeRenovacao $token): void
    {
        $this->em->persist($token);
        $this->em->flush();
    }

    public function revogarTodosDoUsuario(UsuarioId $usuarioId, \DateTimeImmutable $agora): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE token_de_renovacao SET revogado_em = :agora
              WHERE usuario_id = :usuario AND revogado_em IS NULL',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'usuario' => $usuarioId->valor],
        );
    }
}
