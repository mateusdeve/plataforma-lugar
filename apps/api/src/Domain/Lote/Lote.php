<?php

declare(strict_types=1);

namespace Lugar\Domain\Lote;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Domain\Lote\Excecao\ForaDaJanelaDeVenda;
use Lugar\Domain\Lote\Excecao\QuantidadeInvalida;

/**
 * Raiz de agregado. É o limite de consistência transacional do estoque.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE ESTA CLASSE NÃO TEM, E POR QUÊ
 *
 * Ela não guarda a coleção de reservas, e não guarda um contador de
 * `quantidadeReservada`.
 *
 * Não guarda a coleção porque o agregado cresceria sem teto: carregar 5.000
 * reservas para reservar um ingresso é insustentável, e piora exatamente
 * quando o evento faz sucesso (ADR-001).
 *
 * Não guarda o contador porque a verdade sobre o reservado é uma query — as
 * reservas PENDENTES que ainda não expiraram (ADR-002). Um contador ao lado
 * dessa query seria uma segunda verdade sobre o mesmo fato, e duas verdades só
 * podem divergir.
 *
 * Consequência: quem chama precisa INFORMAR quantas unidades estão reservadas
 * neste instante. Parece estranho à primeira vista, e é o desenho correto —
 * esse número vem da query de disponibilidade, executada dentro do mesmo lock
 * pessimista que protege esta linha (fase 2 do PLAN.md).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class Lote
{
    /** RN-04: máximo de 6 ingressos por reserva. */
    public const int MAXIMO_POR_RESERVA = 6;

    public function __construct(
        public readonly LoteId $id,
        public readonly EventoId $eventoId,
        private string $nome,
        private Dinheiro $precoUnitario,
        private int $quantidadeTotal,
        private int $quantidadeVendida,
        private Periodo $janelaDeVenda,
    ) {
        if ($quantidadeTotal < 1) {
            throw new QuantidadeInvalida('Um lote precisa de ao menos 1 lugar.');
        }

        if ($quantidadeVendida < 0) {
            throw new QuantidadeInvalida('Quantidade vendida não pode ser negativa.');
        }

        if ($quantidadeVendida > $quantidadeTotal) {
            throw new QuantidadeInvalida('Vendido não pode exceder o total do lote.');
        }
    }

    /**
     * Disponibilidade real no instante da chamada.
     *
     * @param int $reservadasAtivas reservas PENDENTES cujo prazo ainda não
     *                              venceu, vindas da query do ADR-002
     */
    public function disponivel(int $reservadasAtivas): int
    {
        if ($reservadasAtivas < 0) {
            throw new QuantidadeInvalida('Reservas ativas não podem ser negativas.');
        }

        return max(0, $this->quantidadeTotal - $this->quantidadeVendida - $reservadasAtivas);
    }

    /**
     * Porta de entrada do invariante crítico:
     *
     *     reservado + vendido <= total
     *
     * Chamada DENTRO do lock pessimista, depois de recalcular
     * `$reservadasAtivas`. É o ponto em que a corrida pelo último ingresso é
     * decidida.
     *
     * @throws QuantidadeInvalida   quantidade fora de 1..6 (RN-04)
     * @throws ForaDaJanelaDeVenda  fora da janela de venda (RN-06)
     * @throws EstoqueInsuficiente  não há lugares suficientes (RN-03)
     */
    public function garantirQuePodeReservar(
        int $quantidade,
        int $reservadasAtivas,
        \DateTimeImmutable $agora,
    ): void {
        if ($quantidade < 1) {
            throw new QuantidadeInvalida('É preciso reservar ao menos 1 ingresso.');
        }

        if ($quantidade > self::MAXIMO_POR_RESERVA) {
            throw new QuantidadeInvalida(
                sprintf('Máximo de %d ingressos por reserva.', self::MAXIMO_POR_RESERVA),
            );
        }

        if (!$this->janelaDeVenda->contem($agora)) {
            throw new ForaDaJanelaDeVenda('Este lote não está em venda neste momento.');
        }

        $disponivel = $this->disponivel($reservadasAtivas);

        if ($quantidade > $disponivel) {
            throw new EstoqueInsuficiente($disponivel, $quantidade);
        }
    }

    /**
     * Confirma a venda. Chamado quando o pagamento é aprovado — o estoque sai
     * de "reservado" e entra em "vendido".
     */
    public function registrarVenda(int $quantidade): void
    {
        if ($quantidade < 1) {
            throw new QuantidadeInvalida('Quantidade vendida deve ser positiva.');
        }

        if ($this->quantidadeVendida + $quantidade > $this->quantidadeTotal) {
            // Última linha de defesa no domínio. O banco tem a dele:
            // CHECK (quantidade_vendida <= quantidade_total).
            throw new EstoqueInsuficiente(
                $this->quantidadeTotal - $this->quantidadeVendida,
                $quantidade,
            );
        }

        $this->quantidadeVendida += $quantidade;
    }

    /** RN-11: não é possível reduzir o lote abaixo do que já foi vendido. */
    public function redimensionarPara(int $novoTotal): void
    {
        if ($novoTotal < $this->quantidadeVendida) {
            throw new QuantidadeInvalida(
                sprintf(
                    'Não é possível definir %d lugares: %d já foram vendidos.',
                    $novoTotal,
                    $this->quantidadeVendida,
                ),
            );
        }

        $this->quantidadeTotal = $novoTotal;
    }

    public function esgotou(int $reservadasAtivas): bool
    {
        return 0 === $this->disponivel($reservadasAtivas);
    }

    public function totalPara(int $quantidade): Dinheiro
    {
        return $this->precoUnitario->multiplicadoPor($quantidade);
    }

    public function nome(): string
    {
        return $this->nome;
    }

    public function precoUnitario(): Dinheiro
    {
        return $this->precoUnitario;
    }

    public function quantidadeTotal(): int
    {
        return $this->quantidadeTotal;
    }

    public function quantidadeVendida(): int
    {
        return $this->quantidadeVendida;
    }

    public function janelaDeVenda(): Periodo
    {
        return $this->janelaDeVenda;
    }
}
