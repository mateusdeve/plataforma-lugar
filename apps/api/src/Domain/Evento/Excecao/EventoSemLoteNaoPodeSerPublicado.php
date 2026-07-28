<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * Publicar um evento sem lote colocaria na vitrine algo que não vende nada:
 * o detalhe do evento não teria o que ofertar e o botão de reservar não teria
 * para onde apontar. O rascunho pode existir incompleto; a vitrine, não.
 */
final class EventoSemLoteNaoPodeSerPublicado extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'evento-sem-lote';
    }
}
