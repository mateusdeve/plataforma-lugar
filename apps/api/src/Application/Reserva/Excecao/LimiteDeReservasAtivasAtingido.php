<?php

declare(strict_types=1);

namespace Lugar\Application\Reserva\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * RN-05. Vive em Application/ e não em Domain/ porque a regra atravessa
 * reserva → lote → evento — não é invariante de nenhum agregado (ADR-001).
 */
final class LimiteDeReservasAtivasAtingido extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'limite-reservas-ativas';
    }
}
