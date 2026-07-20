<?php

declare(strict_types=1);

namespace Lugar\Domain\Pagamento;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Reserva\ReservaId;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O REGISTRO DE QUE O DINHEIRO CHEGOU.
 *
 * Raiz de agregado pequena de propósito: guarda o que o provedor disse, e nada
 * mais. Não decide se a reserva vira ingresso — isso é do caso de uso, que
 * enxerga reserva e lote e por isso pode coordenar os dois.
 *
 * O CAMPO QUE IMPEDE INGRESSO DUPLICADO
 *
 * `provedorId` é o identificador do evento no gateway, e tem UNIQUE no banco.
 * Gateway de pagamento reenvia webhook: por timeout, por retentativa, por
 * política de "pelo menos uma entrega". Reprocessar o mesmo evento sem essa
 * chave emitiria uma segunda leva de ingressos para uma compra só.
 *
 * A garantia não está no `if` que consulta antes de gravar — dois webhooks
 * simultâneos passam pelo `if` juntos. Está no índice único, que faz o segundo
 * INSERT falhar. O `if` é otimização; o índice é a regra.
 * ═══════════════════════════════════════════════════════════════════════════
 */
class Pagamento
{
    private function __construct(
        public readonly PagamentoId $id,
        public readonly ReservaId $reservaId,
        /** O id do evento no provedor — a chave de idempotência do webhook. */
        public readonly string $provedorId,
        private Dinheiro $valor,
        private StatusDoPagamento $status,
        /**
         * O payload cru, como chegou.
         *
         * Não é redundância: quando um pagamento for contestado daqui a seis
         * meses, o que resolve a discussão é o que o provedor mandou, não a
         * nossa interpretação dele. Guardar só os campos que hoje interessam é
         * jogar fora a prova de uma transação que existiu.
         */
        private string $payloadBruto,
        public readonly \DateTimeImmutable $recebidoEm,
    ) {
    }

    public static function registrar(
        PagamentoId $id,
        ReservaId $reservaId,
        string $provedorId,
        Dinheiro $valor,
        StatusDoPagamento $status,
        string $payloadBruto,
        \DateTimeImmutable $agora,
    ): self {
        if ('' === trim($provedorId)) {
            // Sem isto, o UNIQUE do banco aceitaria vários pagamentos com
            // string vazia e a idempotência sumiria em silêncio.
            throw new \InvalidArgumentException('O pagamento precisa do id do provedor.');
        }

        return new self($id, $reservaId, $provedorId, $valor, $status, $payloadBruto, $agora);
    }

    public function foiAprovado(): bool
    {
        return StatusDoPagamento::APROVADO === $this->status;
    }

    /**
     * O valor pago confere com o que a reserva cobrava?
     *
     * Parece paranoia e não é: o valor chega numa requisição HTTP vinda de
     * fora. Mesmo com assinatura válida, confirmar uma reserva de R$ 220 com um
     * pagamento de R$ 2 é o tipo de erro que só aparece na conciliação do fim
     * do mês — quando os ingressos já entraram pela porta.
     */
    public function quitaIntegralmente(Dinheiro $totalDaReserva): bool
    {
        return $this->valor->ehIgualA($totalDaReserva);
    }

    public function valor(): Dinheiro
    {
        return $this->valor;
    }

    public function status(): StatusDoPagamento
    {
        return $this->status;
    }

    public function payloadBruto(): string
    {
        return $this->payloadBruto;
    }
}
