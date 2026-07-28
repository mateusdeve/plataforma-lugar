<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\DBAL\LockMode;
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

    /**
     * O lock da porta — ver o comentário na interface.
     *
     * `findOneBy` não aceita LockMode, e `find()` exigiria o id (temos o
     * código). Daí a DQL explícita com `PESSIMISTIC_WRITE`, que o Doctrine
     * traduz em `SELECT ... FOR UPDATE`.
     *
     * O `clear()` antes é pelo mesmo motivo do lote: com o ingresso já no
     * Identity Map, o Doctrine devolveria a cópia em memória sem ir ao banco —
     * e sem lock nenhum. ATENÇÃO ao mesmo efeito colateral: isto desanexa todas
     * as entidades, então nada carregado antes desta chamada continua
     * gerenciado (ver RepositorioDoctrineDeLotes).
     */
    public function buscarParaAtualizacaoPorCodigo(CodigoIngresso $codigo): ?Ingresso
    {
        $this->em->clear();

        $resultado = $this->em
            ->createQuery('SELECT i FROM '.Ingresso::class.' i WHERE i.codigo = :codigo')
            ->setParameter('codigo', $codigo)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        // `getOneOrNullResult()` devolve mixed. Estreitar aqui é mais honesto
        // que anotar `@var` — se um dia a DQL mudar e trouxer outra coisa, o
        // erro aparece nesta linha e não três camadas adiante.
        return $resultado instanceof Ingresso ? $resultado : null;
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
