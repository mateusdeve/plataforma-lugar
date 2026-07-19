<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\RepositorioDeIngressos;
use Lugar\Domain\Reserva\ReservaId;

final readonly class RepositorioDoctrineDeIngressos implements RepositorioDeIngressos
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscarPorCodigo(CodigoIngresso $codigo): ?Ingresso
    {
        return $this->em->getRepository(Ingresso::class)->findOneBy(['codigo' => $codigo]);
    }

    public function salvar(Ingresso $ingresso): void
    {
        $this->em->persist($ingresso);
        $this->em->flush();
    }

    public function daReserva(ReservaId $reservaId): array
    {
        return array_values(
            $this->em->getRepository(Ingresso::class)->findBy(['reservaId' => $reservaId]),
        );
    }
}
