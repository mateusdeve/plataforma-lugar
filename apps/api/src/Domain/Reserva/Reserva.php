<?php

declare(strict_types=1);

namespace Lugar\Domain\Reserva;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Reserva\Excecao\ReservaNaoEstaAtiva;

/**
 * Raiz de agregado própria. Referencia o lote por identidade, nunca por
 * objeto (ADR-001) — é isso que mantém o agregado pequeno e permite gravar
 * reservas sem contenção.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A MÁQUINA DE ESTADOS (PRD §4.3)
 *
 *                   PENDENTE
 *                  /    |    \
 *        CONFIRMADA  EXPIRADA  CANCELADA
 *
 * Os três finais são terminais. Nenhuma transição sai deles.
 *
 * Repare que não existe método para "reativar" ou "estender" uma reserva
 * expirada. É deliberado: enquanto o prazo corria, o estoque ficou retido; ao
 * vencer, ele voltou para o público e pode já ter sido levado por outra
 * pessoa. Ressuscitar a reserva seria vender um lugar que talvez não exista
 * mais. Quem perdeu o prazo cria uma reserva nova e disputa de novo.
 *
 * A EXPIRAÇÃO NÃO É UM EVENTO PROCESSADO
 *
 * Nada marca a reserva como expirada quando o prazo vence — não há cron
 * (ADR-002). `estaAtiva()` compara `expiraEm` com o agora a cada chamada, e é
 * essa comparação que decide se o estoque ainda está retido. O status no banco
 * pode dizer PENDENTE para sempre; a reserva deixou de reter estoque no exato
 * instante em que venceu.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class Reserva
{
    /** RN-01: o prazo é configurável por evento, entre estes limites. */
    public const int PRAZO_MINIMO_MINUTOS = 5;
    public const int PRAZO_MAXIMO_MINUTOS = 30;
    public const int PRAZO_PADRAO_MINUTOS = 10;

    private function __construct(
        public readonly ReservaId $id,
        public readonly LoteId $loteId,
        public readonly string $compradorEmail,
        public readonly int $quantidade,
        public readonly Dinheiro $total,
        public readonly \DateTimeImmutable $criadaEm,
        public readonly \DateTimeImmutable $expiraEm,
        private StatusDaReserva $status,
        /**
         * Chave de idempotência do POST /api/reservas (PRD §6.2). Rede móvel
         * derruba requisição e o usuário aperta o botão de novo; sem isto ele
         * reservaria dois lugares. UNIQUE no banco garante no nível certo.
         */
        public readonly ?string $chaveDeIdempotencia = null,
    ) {
    }

    /**
     * RN-01: a reserva nasce PENDENTE e com prazo contado a partir de agora.
     */
    public static function criar(
        ReservaId $id,
        LoteId $loteId,
        string $compradorEmail,
        int $quantidade,
        Dinheiro $total,
        \DateTimeImmutable $agora,
        int $prazoEmMinutos = self::PRAZO_PADRAO_MINUTOS,
        ?string $chaveDeIdempotencia = null,
    ): self {
        if ($prazoEmMinutos < self::PRAZO_MINIMO_MINUTOS || $prazoEmMinutos > self::PRAZO_MAXIMO_MINUTOS) {
            throw new \InvalidArgumentException(
                sprintf(
                    'O prazo da reserva deve ficar entre %d e %d minutos.',
                    self::PRAZO_MINIMO_MINUTOS,
                    self::PRAZO_MAXIMO_MINUTOS,
                ),
            );
        }

        if (!filter_var($compradorEmail, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail do comprador inválido.');
        }

        return new self(
            $id,
            $loteId,
            $compradorEmail,
            $quantidade,
            $total,
            $agora,
            $agora->modify(sprintf('+%d minutes', $prazoEmMinutos)),
            StatusDaReserva::PENDENTE,
            $chaveDeIdempotencia,
        );
    }

    /**
     * RN-02: ativa é PENDENTE **e** ainda dentro do prazo. Só reserva ativa
     * retém estoque.
     */
    public function estaAtiva(\DateTimeImmutable $agora): bool
    {
        return StatusDaReserva::PENDENTE === $this->status && $this->expiraEm > $agora;
    }

    public function expirou(\DateTimeImmutable $agora): bool
    {
        return StatusDaReserva::PENDENTE === $this->status && $this->expiraEm <= $agora;
    }

    /**
     * RN-07: só se pode confirmar uma reserva ativa. Confirmar uma expirada é
     * erro 409 com `type=reserva-expirada`.
     */
    public function confirmar(\DateTimeImmutable $agora): void
    {
        if (!$this->estaAtiva($agora)) {
            throw new ReservaNaoEstaAtiva(
                StatusDaReserva::PENDENTE === $this->status
                    ? 'O prazo desta reserva venceu.'
                    : sprintf('Esta reserva está %s.', $this->status->value),
            );
        }

        $this->status = StatusDaReserva::CONFIRMADA;
    }

    public function cancelar(\DateTimeImmutable $agora): void
    {
        if (!$this->estaAtiva($agora)) {
            throw new ReservaNaoEstaAtiva('Só é possível cancelar uma reserva ativa.');
        }

        $this->status = StatusDaReserva::CANCELADA;
    }

    /**
     * Marca a reserva como expirada.
     *
     * Isto é HIGIENE DE DADOS, não correção de estoque (ADR-002). O estoque já
     * voltou sozinho no instante do vencimento, porque `estaAtiva()` passou a
     * responder falso. Este método serve ao job noturno opcional e ao
     * relatório de conversão — o sistema funciona igual se ele nunca rodar.
     */
    public function marcarComoExpirada(\DateTimeImmutable $agora): void
    {
        if (!$this->expirou($agora)) {
            throw new \LogicException('Só uma reserva vencida pode ser marcada como expirada.');
        }

        $this->status = StatusDaReserva::EXPIRADA;
    }

    public function segundosRestantes(\DateTimeImmutable $agora): int
    {
        if (!$this->estaAtiva($agora)) {
            return 0;
        }

        return max(0, $this->expiraEm->getTimestamp() - $agora->getTimestamp());
    }

    public function status(): StatusDaReserva
    {
        return $this->status;
    }
}
