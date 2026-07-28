<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso;

use Lugar\Domain\Reserva\ReservaId;

interface RepositorioDeIngressos
{
    public function buscarPorCodigo(CodigoIngresso $codigo): ?Ingresso;

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * O LOCK DA PORTA.
     *
     * RN-10 diz que um ingresso entra uma vez só. Sem lock, isso vale para
     * leituras espaçadas e falha exatamente onde importa: duas catracas lendo
     * o mesmo código no mesmo instante.
     *
     * Os dois processos leem `status = EMITIDO`, os dois concluem "pode
     * entrar", os dois gravam. Duas pessoas entram com um ingresso — que é o
     * print do WhatsApp compartilhado, o caso que a regra existe para pegar.
     *
     * `SELECT ... FOR UPDATE` serializa: o segundo espera o primeiro commitar
     * e então relê o status já `UTILIZADO`.
     *
     * É a mesma decisão do lote na fase 2, pelo mesmo motivo — só que aqui o
     * recurso disputado é o direito de entrar, não o de reservar.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function buscarParaAtualizacaoPorCodigo(CodigoIngresso $codigo): ?Ingresso;

    public function salvar(Ingresso $ingresso): void;

    /** @return list<Ingresso> */
    public function daReserva(ReservaId $reservaId): array;
}
