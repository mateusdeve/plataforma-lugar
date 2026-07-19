<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Lote\RepositorioDeLotes;

final readonly class RepositorioDoctrineDeLotes implements RepositorioDeLotes
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscar(LoteId $id): ?Lote
    {
        return $this->em->find(Lote::class, $id);
    }

    /**
     * O LOCK. Traduz para `SELECT ... FOR UPDATE` no Postgres.
     *
     * Duas coisas que parecem detalhe e não são:
     *
     * 1. `LockMode::PESSIMISTIC_WRITE` e não `PESSIMISTIC_READ`. O read
     *    permite que outros também leiam com lock compartilhado — e dois
     *    leitores concluiriam, os dois, que há estoque. É write que serializa.
     *
     * 2. `$this->em->clear()` antes do find. Sem isso, se o lote já estiver
     *    no Identity Map do Doctrine, o `find` devolve o objeto EM MEMÓRIA e
     *    NÃO vai ao banco — nenhum SELECT, nenhum lock, e a corrida acontece
     *    com uma cópia velha do estoque. É o tipo de bug que não aparece em
     *    teste sequencial e destrói o invariante sob concorrência.
     */
    public function buscarParaAtualizacao(LoteId $id): ?Lote
    {
        $this->em->clear();

        return $this->em->find(Lote::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function salvar(Lote $lote): void
    {
        $this->em->persist($lote);
        $this->em->flush();
    }
}
