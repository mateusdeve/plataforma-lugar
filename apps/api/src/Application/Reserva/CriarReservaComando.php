<?php

declare(strict_types=1);

namespace Lugar\Application\Reserva;

/**
 * Entrada do caso de uso. DTO puro: sem lógica, sem framework, sem validação
 * de formato — quem valida entrada HTTP é a camada UI.
 */
final readonly class CriarReservaComando
{
    public function __construct(
        public string $loteId,
        public string $compradorEmail,
        public int $quantidade,
        /** Header Idempotency-Key, quando enviado (PRD §6.2). */
        public ?string $chaveDeIdempotencia = null,
    ) {
    }
}
