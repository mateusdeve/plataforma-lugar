<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

/**
 * Dinheiro em centavos, sempre inteiro. Nunca float (PRD §8).
 *
 * Float não representa 0,10 exatamente. Somar dez vezes R$ 0,10 em float não
 * dá R$ 1,00 — dá 0,9999999999999999. Num sistema que soma preços de ingresso
 * e reporta receita para um organizador, isso é dinheiro sumindo do relatório.
 *
 * Por isso a menor unidade é o centavo e a divisão não existe nesta classe:
 * dividir dinheiro exige decidir o que fazer com o resto, e essa decisão é do
 * caso de uso, não do Value Object.
 */
final readonly class Dinheiro
{
    private function __construct(
        public int $centavos,
        public string $moeda,
    ) {
    }

    public static function emCentavos(int $centavos, string $moeda = 'BRL'): self
    {
        if ($centavos < 0) {
            throw new \InvalidArgumentException('Dinheiro não pode ser negativo.');
        }

        return new self($centavos, $moeda);
    }

    public static function zero(string $moeda = 'BRL'): self
    {
        return new self(0, $moeda);
    }

    public function multiplicadoPor(int $fator): self
    {
        if ($fator < 0) {
            throw new \InvalidArgumentException('Fator não pode ser negativo.');
        }

        return new self($this->centavos * $fator, $this->moeda);
    }

    public function somadoA(self $outro): self
    {
        $this->garantirMesmaMoeda($outro);

        return new self($this->centavos + $outro->centavos, $this->moeda);
    }

    public function ehIgualA(self $outro): bool
    {
        return $this->centavos === $outro->centavos && $this->moeda === $outro->moeda;
    }

    public function ehMaiorQue(self $outro): bool
    {
        $this->garantirMesmaMoeda($outro);

        return $this->centavos > $outro->centavos;
    }

    private function garantirMesmaMoeda(self $outro): void
    {
        if ($this->moeda !== $outro->moeda) {
            // Somar BRL com USD é sempre bug. Falhar aqui é melhor que
            // devolver um número que ninguém sabe o que significa.
            throw new \InvalidArgumentException(
                sprintf('Moedas diferentes: %s e %s.', $this->moeda, $outro->moeda),
            );
        }
    }
}
