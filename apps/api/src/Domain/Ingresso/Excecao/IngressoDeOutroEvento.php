<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * O ingresso é VÁLIDO — só não é desta porta.
 *
 * PLAN 7.3. A distinção importa na fila: "código inválido" manda a pessoa
 * procurar o e-mail achando que errou a digitação; "este ingresso é do
 * NextConf, não do FrontZ" manda ela para o lugar certo.
 *
 * Acontece de verdade em casa de show com duas salas, e em fim de semana com
 * dois eventos do mesmo organizador no mesmo prédio.
 */
final class IngressoDeOutroEvento extends ViolacaoDeRegraDeNegocio
{
    public function __construct(public readonly string $eventoDoIngresso)
    {
        parent::__construct('Este ingresso é de outro evento.');
    }

    public function tipo(): string
    {
        return 'ingresso-de-outro-evento';
    }
}
