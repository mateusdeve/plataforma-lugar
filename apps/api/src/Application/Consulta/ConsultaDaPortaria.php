<?php

declare(strict_types=1);

namespace Lugar\Application\Consulta;

/**
 * Lado de leitura da porta.
 *
 * A tela mostra nome, lote e quantidade — dados que atravessam ingresso →
 * reserva → lote → usuário. Carregar quatro agregados para exibir três linhas,
 * na tela usada sob pressão com internet ruim (PLAN 7.6, p95 < 200ms), seria
 * pagar caro por nada.
 */
interface ConsultaDaPortaria
{
    /**
     * @return array<string, mixed>|null
     */
    public function porCodigo(string $codigo): ?array;

    /** Quantas entradas já foram validadas neste evento — o contador da tela. */
    public function entradasDoEvento(string $eventoId): int;
}
