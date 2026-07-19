<?php

declare(strict_types=1);

namespace Lugar\Domain\Reserva;

use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Lote\LoteId;

interface RepositorioDeReservas
{
    public function buscar(ReservaId $id): ?Reserva;

    public function salvar(Reserva $reserva): void;

    /**
     * Soma as unidades retidas por reservas ATIVAS no lote (ADR-002):
     * status PENDENTE **e** expira_em > agora.
     *
     * É a query mais quente do sistema e a razão do índice parcial
     * `reserva (lote_id) WHERE status = 'PENDENTE'`.
     *
     * Chamada DENTRO do lock do lote, para que o número não mude entre a
     * leitura e a decisão.
     */
    public function contarUnidadesAtivasNoLote(LoteId $loteId, \DateTimeImmutable $agora): int;

    /**
     * RN-05: quantas reservas ativas este e-mail tem neste evento.
     *
     * Atravessa reserva → lote → evento, e por isso NÃO é invariante de
     * agregado (ADR-001) — vive na camada de aplicação. Ver PLAN.md §2.
     */
    public function contarReservasAtivasDoCompradorNoEvento(
        string $email,
        EventoId $eventoId,
        \DateTimeImmutable $agora,
    ): int;

    /** Reserva já criada com esta chave de idempotência, se houver (PRD §6.2). */
    public function buscarPorChaveDeIdempotencia(string $chave): ?Reserva;
}
