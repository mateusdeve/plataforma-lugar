<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso;

/**
 * RN-09: código aleatório e não adivinhável. Formato LGR-XXXX-XXXX.
 *
 * Duas decisões que parecem cosméticas e não são:
 *
 * 1. NÃO É SEQUENCIAL. Com `ingresso/1`, `ingresso/2`, qualquer pessoa enumera
 *    a base inteira e descobre quantos ingressos foram vendidos — ou pior,
 *    tenta validar códigos que não são seus.
 *
 * 2. O ALFABETO EXCLUI CARACTERES AMBÍGUOS: sem I, O, 0, 1, L. Na porta do
 *    evento, alguém vai ditar o código em voz alta ou digitar olhando para um
 *    celular com a tela rachada. "É zero ou letra O?" é uma pergunta que custa
 *    tempo numa fila.
 */
final readonly class CodigoIngresso
{
    public const string ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const string PADRAO = '/^LGR-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public function __construct(public string $valor)
    {
        if (1 !== preg_match(self::PADRAO, $valor)) {
            throw new \InvalidArgumentException(
                sprintf('Código de ingresso inválido: %s', $valor),
            );
        }
    }

    public function ehIgualA(self $outro): bool
    {
        return $this->valor === $outro->valor;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
