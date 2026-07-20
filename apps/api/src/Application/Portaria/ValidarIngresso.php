<?php

declare(strict_types=1);

namespace Lugar\Application\Portaria;

use Lugar\Application\Comum\Transacao;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\Excecao\IngressoDeOutroEvento;
use Lugar\Domain\Ingresso\Excecao\IngressoJaUtilizado;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\RepositorioDeIngressos;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Reserva\RepositorioDeReservas;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A PORTA.
 *
 * Critério de pronto da fase 7 (PLAN.md): "ler o mesmo código duas vezes dá
 * verde e depois vermelho com horário, e ler um código de outro evento dá
 * vermelho com o motivo certo".
 *
 * TUDO ACONTECE SOB LOCK
 *
 * A tentação é achar que validar ingresso é uma leitura seguida de um UPDATE
 * simples. Não é: é uma disputa. O print do ingresso circula no grupo da
 * família, e duas pessoas chegam em catracas diferentes ao mesmo tempo.
 *
 * Sem `SELECT ... FOR UPDATE`, as duas leem `EMITIDO`, as duas concluem "pode
 * entrar", e as duas entram. O sintoma aparece na contagem de público no fim
 * da noite, quando não há mais o que fazer.
 *
 * É a mesma decisão do lote na fase 2 — o recurso disputado aqui é o direito
 * de atravessar a porta.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class ValidarIngresso
{
    public function __construct(
        private Transacao $transacao,
        private RepositorioDeIngressos $ingressos,
        private RepositorioDeReservas $reservas,
        private RepositorioDeLotes $lotes,
        private Relogio $relogio,
    ) {
    }

    /**
     * @param string $eventoDaPortaId de qual porta veio a leitura — não do que
     *                                o ingresso diz, mas de onde o leitor está
     *
     * @throws IngressoJaUtilizado    RN-10, com o horário da primeira entrada
     * @throws IngressoDeOutroEvento  PLAN 7.3 — válido, mas não é desta porta
     */
    public function __invoke(string $codigo, string $eventoDaPortaId): Ingresso
    {
        return $this->transacao->executar(function () use ($codigo, $eventoDaPortaId): Ingresso {
            /*
              O lock vem PRIMEIRO, e nada é carregado antes dele.

              `buscarParaAtualizacaoPorCodigo` chama `EntityManager::clear()`
              para forçar a ida ao banco — e isso desanexa qualquer entidade já
              carregada. Reserva e lote são buscados DEPOIS, de propósito. Ver
              o comentário em RepositorioDoctrineDeLotes.
            */
            $ingresso = $this->ingressos->buscarParaAtualizacaoPorCodigo($this->codigo($codigo))
                ?? throw new CodigoDesconhecido($codigo);

            $this->garantirQueEDestaPorta($ingresso, $eventoDaPortaId);

            // RN-10 vive no agregado: é ele que sabe se já foi usado e quando.
            $ingresso->utilizar($this->relogio->agora());

            $this->ingressos->salvar($ingresso);

            return $ingresso;
        });
    }

    /**
     * Código malformado e código inexistente são a MESMA coisa na porta.
     *
     * `new CodigoIngresso('ABC')` estoura `InvalidArgumentException`, que a
     * borda HTTP traduziria em 422 "dados inválidos" — resposta correta para
     * uma API e inútil para quem está na fila. Quem digitou errado precisa ler
     * "confira a digitação", e é o mesmo texto de quem digitou um código que
     * não existe.
     */
    private function codigo(string $bruto): CodigoIngresso
    {
        try {
            return new CodigoIngresso(mb_strtoupper(trim($bruto)));
        } catch (\InvalidArgumentException) {
            throw new CodigoDesconhecido($bruto);
        }
    }

    /**
     * PLAN 7.3 — a checagem que atravessa ingresso → reserva → lote → evento.
     *
     * Não é invariante de agregado (ADR-001): o `Ingresso` conhece a reserva e
     * para por aí. Quem enxerga a cadeia inteira é a aplicação, e é aqui que a
     * regra mora — a mesma decisão tomada para a RN-05 na fase 2.
     *
     * A comparação é com a porta, não com o que o ingresso "acha" que é. O
     * operador foi autorizado para UM evento (PortariaVoter); um ingresso de
     * outro é recusa, mesmo sendo perfeitamente válido no evento dele.
     */
    private function garantirQueEDestaPorta(Ingresso $ingresso, string $eventoDaPortaId): void
    {
        $reserva = $this->reservas->buscar($ingresso->reservaId)
            ?? throw new \RuntimeException(sprintf('Reserva %s não existe.', $ingresso->reservaId));

        $lote = $this->lotes->buscar($reserva->loteId)
            ?? throw new \RuntimeException(sprintf('Lote %s não existe.', $reserva->loteId));

        if ($lote->eventoId->valor !== $eventoDaPortaId) {
            throw new IngressoDeOutroEvento($lote->eventoId->valor);
        }
    }
}
