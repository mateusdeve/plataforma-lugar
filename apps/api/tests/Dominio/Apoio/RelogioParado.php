<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Apoio;

use Lugar\Domain\Comum\Relogio;

/**
 * Relógio de teste: para no instante informado e só anda quando mandam.
 *
 * É o que permite testar "a reserva expira em 10 minutos" em microssegundos,
 * de forma determinística, em vez de esperar 10 minutos e torcer.
 */
final class RelogioParado implements Relogio
{
    private \DateTimeImmutable $agora;

    public function __construct(string $instante = '2026-07-01 10:00:00')
    {
        $this->agora = new \DateTimeImmutable($instante);
    }

    public function agora(): \DateTimeImmutable
    {
        return $this->agora;
    }

    public function avancar(string $intervalo): void
    {
        $this->agora = $this->agora->modify($intervalo);
    }
}
