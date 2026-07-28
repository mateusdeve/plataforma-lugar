<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * Cancelar é terminal. Um evento cancelado teve compradores avisados e
 * expectativas desfeitas — "despublicar o cancelamento" reabriria a venda de
 * algo que já foi declarado morto. Quem cancelou por engano cria outro evento.
 */
final class EventoCanceladoNaoPodeSerPublicado extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'evento-cancelado';
    }
}
