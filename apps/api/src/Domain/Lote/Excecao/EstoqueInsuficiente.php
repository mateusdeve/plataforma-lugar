<?php

declare(strict_types=1);

namespace Lugar\Domain\Lote\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/** RN-03. Vira 409 com type=estoque-insuficiente — o banner da tela do evento. */
final class EstoqueInsuficiente extends ViolacaoDeRegraDeNegocio
{
    public function __construct(
        public readonly int $disponivel,
        public readonly int $solicitado,
    ) {
        parent::__construct(
            sprintf('Restam %d lugares e foram pedidos %d.', $disponivel, $solicitado),
        );
    }

    public function tipo(): string
    {
        return 'estoque-insuficiente';
    }
}
