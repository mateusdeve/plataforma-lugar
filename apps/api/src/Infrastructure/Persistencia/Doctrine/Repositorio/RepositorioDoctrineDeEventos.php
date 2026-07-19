<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;

final readonly class RepositorioDoctrineDeEventos implements RepositorioDeEventos
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscar(EventoId $id): ?Evento
    {
        return $this->em->find(Evento::class, $id);
    }

    public function salvar(Evento $evento): void
    {
        $this->em->persist($evento);
        $this->em->flush();
    }
}
