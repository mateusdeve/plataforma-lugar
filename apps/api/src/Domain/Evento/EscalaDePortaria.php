<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

use Lugar\Domain\Usuario\UsuarioId;

/**
 * Quem pode validar ingresso em qual evento.
 *
 * É o vínculo da portaria, equivalente ao `organizadorId` do evento. Sem ele,
 * ROLE_PORTARIA autorizaria validar ingresso de QUALQUER evento — inclusive
 * de outro organizador, em outra cidade, no mesmo dia.
 *
 * Tabela `evento_operador`, chave primária composta (evento_id, usuario_id).
 */
interface EscalaDePortaria
{
    public function estaEscalado(UsuarioId $usuarioId, EventoId $eventoId): bool;

    public function escalar(UsuarioId $usuarioId, EventoId $eventoId): void;

    public function retirarDaEscala(UsuarioId $usuarioId, EventoId $eventoId): void;

    /** @return list<UsuarioId> */
    public function operadoresDo(EventoId $eventoId): array;
}
