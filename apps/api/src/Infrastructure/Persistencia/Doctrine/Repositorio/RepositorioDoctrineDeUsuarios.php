<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;
use Lugar\Domain\Usuario\UsuarioId;

final readonly class RepositorioDoctrineDeUsuarios implements RepositorioDeUsuarios
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscar(UsuarioId $id): ?Usuario
    {
        return $this->em->find(Usuario::class, $id);
    }

    public function buscarPorEmail(string $email): ?Usuario
    {
        return $this->em->getRepository(Usuario::class)
            ->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function salvar(Usuario $usuario): void
    {
        $this->em->persist($usuario);
        $this->em->flush();
    }
}
