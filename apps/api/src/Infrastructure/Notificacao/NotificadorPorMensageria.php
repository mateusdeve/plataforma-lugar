<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Notificacao;

use Lugar\Application\Notificacao\Notificador;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Infrastructure\Observabilidade\ContextoDeCorrelacao;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O DESPACHO ACONTECE DENTRO DA TRANSAÇÃO — E ISSO É DE PROPÓSITO.
 *
 * A intuição diz o contrário: "não faça I/O dentro de transação". Mas aqui não
 * há I/O nenhum. O transporte é `doctrine://` (messenger.yaml), então despachar
 * é um INSERT numa tabela do MESMO banco, na MESMA transação da confirmação.
 *
 * Isso dá uma garantia que despachar depois do commit não daria:
 *
 *   · se a transação der rollback, a mensagem some junto — ninguém recebe
 *     "sua compra foi confirmada" de uma compra que não aconteceu;
 *   · se a transação commitar, a mensagem está commitada junto — não existe
 *     janela onde os ingressos existem e o aviso se perdeu.
 *
 * É o padrão *outbox*, de graça, por o transporte morar no mesmo banco. Trocar
 * para AMQP no futuro (messenger.yaml diz que é uma linha) perde esta
 * propriedade — e aí este comentário vira o aviso de que é preciso uma tabela
 * de outbox de verdade.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class NotificadorPorMensageria implements Notificador
{
    public function __construct(
        private MessageBusInterface $barramento,
        private ContextoDeCorrelacao $correlacao,
    ) {
    }

    public function confirmacaoDeCompra(Reserva $reserva, array $ingressos): void
    {
        $this->barramento->dispatch(new EnviarConfirmacaoDeCompra(
            reservaId: $reserva->id->valor,
            compradorEmail: $reserva->compradorEmail,
            codigos: array_map(
                static fn (Ingresso $ingresso): string => $ingresso->codigo->valor,
                $ingressos,
            ),
            totalCentavos: $reserva->total->centavos,
            correlationId: $this->correlacao->atual(),
        ));
    }
}
