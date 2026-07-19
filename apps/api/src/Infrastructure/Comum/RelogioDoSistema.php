<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Comum;

use Lugar\Domain\Comum\Relogio;

/** O relógio de produção. Nos testes, um relógio parado toma este lugar. */
final readonly class RelogioDoSistema implements Relogio
{
    public function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
