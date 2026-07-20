<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Domain\Reserva\Reserva;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A PORTA DO GATEWAY.
 *
 * Existe para que trocar de provedor seja escrever uma classe, e não caçar
 * chamadas de `Stripe\` espalhadas por controllers e casos de uso.
 *
 * Repare no que ela NÃO expõe: nada de `PaymentIntent`, `client_secret`,
 * `line_items` ou qualquer substantivo de um provedor específico. O dia em que
 * um desses vazar para esta interface, ela deixou de ser porta e virou o Stripe
 * com outro nome — e a troca volta a custar uma refatoração.
 *
 * A implementação de demonstração roda sem chave nenhuma, para que
 * `docker compose up` funcione para quem clonar o repositório (PRD §14).
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface GatewayDePagamento
{
    /**
     * Abre uma cobrança para a reserva e devolve para onde mandar o comprador.
     *
     * Recebe a `Reserva` inteira, e não só o valor, porque o provedor precisa
     * do e-mail para o recibo e do id para reconciliar o webhook depois.
     */
    public function abrirCobranca(Reserva $reserva): CobrancaAberta;

    /** Aparece na tela para a pessoa saber com quem está pagando. */
    public function nome(): string;
}
