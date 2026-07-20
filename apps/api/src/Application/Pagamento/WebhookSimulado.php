<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

/**
 * Uma requisição de webhook pronta para ser verificada — igualzinha à que o
 * provedor enviaria, cabeçalhos inclusive.
 */
final readonly class WebhookSimulado
{
    /**
     * @param array<string, string> $cabecalhos
     */
    public function __construct(
        public string $corpo,
        public array $cabecalhos,
    ) {
    }
}
