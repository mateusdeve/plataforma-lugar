<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/** RN-12: com venda confirmada, o evento só pode ser cancelado. */
final class EventoComVendasNaoPodeSerExcluido extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'evento-com-vendas';
    }
}
