<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Domain\Comum\Dinheiro;

/**
 * O que um webhook de pagamento diz, já traduzido do dialeto do provedor.
 *
 * Só pode ser construído por um `WebhookDePagamento`, depois da assinatura
 * conferida. Ter este objeto em mãos é a prova de que a verificação passou.
 */
final readonly class AvisoDePagamento
{
    public function __construct(
        /** Id do evento no provedor. É a chave de idempotência (UNIQUE). */
        public string $provedorId,
        public string $reservaId,
        public Dinheiro $valor,
        public bool $aprovado,
        /** O payload cru, para o dia da contestação. */
        public string $payloadBruto,
    ) {
    }
}
