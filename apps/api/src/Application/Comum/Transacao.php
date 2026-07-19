<?php

declare(strict_types=1);

namespace Lugar\Application\Comum;

/**
 * Porta de transação.
 *
 * O caso de uso precisa dizer "isto é atômico" sem conhecer Doctrine — a
 * camada Application não pode importar framework. A implementação envolve o
 * callback em begin/commit/rollback.
 *
 * @see \Lugar\Application\Reserva\CriarReserva onde o lock pessimista vive
 */
interface Transacao
{
    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    public function executar(callable $operacao): mixed;
}
