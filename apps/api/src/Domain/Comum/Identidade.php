<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

/**
 * Base das identidades. `LoteId` e `ReservaId` são tipos distintos justamente
 * para que o compilador impeça passar um no lugar do outro — com `string` em
 * toda parte, essa troca só aparece em produção.
 *
 * O domínio não gera identidades: quem gera é a infraestrutura, através da
 * porta GeradorDeIdentidade. Assim o domínio não precisa conhecer UUID, e os
 * testes usam ids legíveis.
 */
abstract readonly class Identidade
{
    final public function __construct(public string $valor)
    {
        if ('' === trim($valor)) {
            throw new \InvalidArgumentException('Identidade não pode ser vazia.');
        }
    }

    final public function ehIgualA(self $outra): bool
    {
        return static::class === $outra::class && $this->valor === $outra->valor;
    }

    final public function __toString(): string
    {
        return $this->valor;
    }
}
