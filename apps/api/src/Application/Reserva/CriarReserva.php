<?php

declare(strict_types=1);

namespace Lugar\Application\Reserva;

use Lugar\Application\Comum\Transacao;
use Lugar\Application\Reserva\Excecao\LimiteDeReservasAtivasAtingido;
use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O CORAÇÃO DO SISTEMA
 *
 * Duas pessoas clicam em "comprar" no último ingresso no mesmo milissegundo.
 * Sem tratamento, o banco aceita as duas e o evento tem 501 pessoas para 500
 * lugares. Este arquivo é o que impede isso.
 *
 * A sequência importa, e cada passo tem uma razão:
 *
 *   1. Abre transação
 *   2. RN-05 — verifica o limite por e-mail (antes do lock: é consulta barata
 *      e não precisa da linha travada)
 *   3. SELECT ... FOR UPDATE no lote  ← A PARTIR DAQUI, SÓ UM PASSA
 *   4. Recalcula as reservas ativas DENTRO do lock (ADR-002)
 *   5. Verifica o invariante: reservado + vendido <= total
 *   6. Grava a reserva
 *   7. Commit — e só então o próximo concorrente é liberado
 *
 * POR QUE LOCK PESSIMISTA, E NÃO OTIMISTA
 *
 * Com lock otimista (coluna `version` + retry), sob disputa alta no último
 * ingresso, quase toda transação falharia na comparação de versão e teria de
 * tentar de novo. A taxa de retry explode exatamente no momento de maior
 * carga — o pior lugar possível para adicionar trabalho.
 *
 * O pessimista faz os concorrentes ESPERAREM no banco em vez de tentarem e
 * falharem. Cada um relê o estoque já atualizado e decide com informação
 * correta. O otimista continua sendo a escolha certa para edição de evento,
 * onde a disputa é baixa (PRD §6.1).
 *
 * POR QUE O PASSO 4 EXISTE
 *
 * A disponibilidade lida ANTES do lock é história — pode ter mudado enquanto
 * se esperava a linha liberar. Recalcular dentro do lock é o que transforma
 * "estava disponível" em "está disponível".
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class CriarReserva
{
    /** RN-05: no máximo 2 reservas ativas por e-mail no mesmo evento. */
    public const int MAXIMO_RESERVAS_ATIVAS_POR_EVENTO = 2;

    public function __construct(
        private Transacao $transacao,
        private RepositorioDeLotes $lotes,
        private RepositorioDeReservas $reservas,
        private RepositorioDeEventos $eventos,
        private GeradorDeIdentidade $gerador,
        private Relogio $relogio,
    ) {
    }

    /**
     * @throws EstoqueInsuficiente             409 type=estoque-insuficiente
     * @throws LimiteDeReservasAtivasAtingido  409 type=limite-reservas-ativas
     */
    public function __invoke(CriarReservaComando $comando): Reserva
    {
        // Idempotência (PRD §6.2). Verificada FORA da transação porque é uma
        // leitura barata e o caso comum é ela não encontrar nada. O UNIQUE em
        // `reserva.idempotency_key` é quem garante de verdade, se duas
        // requisições passarem por aqui ao mesmo tempo.
        if (null !== $comando->chaveDeIdempotencia) {
            $jaExiste = $this->reservas->buscarPorChaveDeIdempotencia($comando->chaveDeIdempotencia);

            if (null !== $jaExiste) {
                return $jaExiste;
            }
        }

        return $this->transacao->executar(function () use ($comando): Reserva {
            $agora = $this->relogio->agora();
            $loteId = new LoteId($comando->loteId);

            // ── passo 2 · RN-05 ────────────────────────────────────────────
            // Atravessa reserva → lote → evento, e por isso vive aqui e não no
            // agregado (ADR-001, PLAN.md §2). É a regra mais fraca do conjunto:
            // depende de tempo (`expira_em > agora`), e nenhum UNIQUE expressa
            // isso — então NÃO existe rede de proteção no banco para ela. Duas
            // requisições simultâneas do mesmo e-mail podem passar as duas.
            // Aceito conscientemente: o dano é alguém segurar 3 reservas em vez
            // de 2, o que é irrelevante perto de vender o mesmo lugar duas vezes.
            $eventoId = $this->exigirLote($loteId)->eventoId;

            $ativasDoComprador = $this->reservas->contarReservasAtivasDoCompradorNoEvento(
                $comando->compradorEmail,
                $eventoId,
                $agora,
            );

            if ($ativasDoComprador >= self::MAXIMO_RESERVAS_ATIVAS_POR_EVENTO) {
                throw new LimiteDeReservasAtivasAtingido(
                    sprintf(
                        'Você já tem %d reservas ativas neste evento. Conclua ou cancele uma delas.',
                        $ativasDoComprador,
                    ),
                );
            }

            // ── passo 3 · O LOCK ──────────────────────────────────────────
            // A partir daqui, qualquer outra transação que peça esta mesma
            // linha fica BLOQUEADA no Postgres até o commit lá embaixo.
            $lote = $this->lotes->buscarParaAtualizacao($loteId)
                ?? throw new \RuntimeException(sprintf('Lote %s não existe.', $loteId));


            // ── passo 4 · recalcular dentro do lock ───────────────────────
            $reservadasAtivas = $this->reservas->contarUnidadesAtivasNoLote($loteId, $agora);

            // ── passo 5 · o invariante ────────────────────────────────────
            $lote->garantirQuePodeReservar($comando->quantidade, $reservadasAtivas, $agora);

            // ── passo 6 · gravar ──────────────────────────────────────────
            $evento = $this->eventos->buscar($lote->eventoId);

            $reserva = Reserva::criar(
                $this->gerador->novaReservaId(),
                $loteId,
                $comando->compradorEmail,
                $comando->quantidade,
                $lote->totalPara($comando->quantidade),
                $agora,
                $evento?->prazoReservaMinutos() ?? Reserva::PRAZO_PADRAO_MINUTOS,
                $comando->chaveDeIdempotencia,
            );

            $this->reservas->salvar($reserva);

            return $reserva;
            // ── passo 7 · commit acontece ao sair daqui ───────────────────
        });
    }

    private function exigirLote(LoteId $id): Lote
    {
        return $this->lotes->buscar($id)
            ?? throw new \RuntimeException(sprintf('Lote %s não existe.', $id));
    }
}
