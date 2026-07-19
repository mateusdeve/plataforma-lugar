<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Application\Comum\Transacao;

/**
 * Implementa a porta de transação com o EntityManager.
 *
 * `wrapInTransaction` faz begin/commit e, em qualquer exceção, rollback —
 * inclusive nas exceções de regra de negócio. É o que garante que uma reserva
 * recusada por estoque não deixe nada gravado pela metade.
 */
final readonly class TransacaoDoctrine implements Transacao
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function executar(callable $operacao): mixed
    {
        return $this->em->wrapInTransaction($operacao);
    }
}
