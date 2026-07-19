<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

/**
 * Intervalo de tempo com fim opcional — a janela de venda de um lote (RN-06).
 *
 * Fim nulo significa "sem data para encerrar", que é o caso do último lote de
 * um evento. Modelar isso como nulo é mais honesto que inventar uma data no
 * ano 9999 e ter que lembrar dela em toda comparação.
 */
final readonly class Periodo
{
    private function __construct(
        public \DateTimeImmutable $inicio,
        public ?\DateTimeImmutable $fim,
    ) {
    }

    public static function de(\DateTimeImmutable $inicio, ?\DateTimeImmutable $fim = null): self
    {
        if (null !== $fim && $fim <= $inicio) {
            throw new \InvalidArgumentException('O fim do período deve ser posterior ao início.');
        }

        return new self($inicio, $fim);
    }

    public static function aPartirDe(\DateTimeImmutable $inicio): self
    {
        return new self($inicio, null);
    }

    public function contem(\DateTimeImmutable $instante): bool
    {
        if ($instante < $this->inicio) {
            return false;
        }

        return null === $this->fim || $instante <= $this->fim;
    }

    public function jaComecouEm(\DateTimeImmutable $instante): bool
    {
        return $instante >= $this->inicio;
    }

    public function jaTerminouEm(\DateTimeImmutable $instante): bool
    {
        return null !== $this->fim && $instante > $this->fim;
    }
}
