<?php

declare(strict_types=1);

namespace Lugar\Application\Portaria;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * O código não existe.
 *
 * Na porta isso quase sempre é digitação errada, não fraude — daí a mensagem
 * sugerir conferir em vez de acusar. O alfabeto do `CodigoIngresso` já exclui
 * caracteres ambíguos justamente para reduzir este caso.
 *
 * Vira 404 em vez de 422: a diferença entre "não existe" e "existe e não pode"
 * é o que a tela da portaria precisa para escolher a mensagem certa.
 */
final class CodigoDesconhecido extends ViolacaoDeRegraDeNegocio
{
    public function __construct(public readonly string $codigo)
    {
        parent::__construct('Código não encontrado. Confira a digitação.');
    }

    public function tipo(): string
    {
        return 'codigo-desconhecido';
    }
}
