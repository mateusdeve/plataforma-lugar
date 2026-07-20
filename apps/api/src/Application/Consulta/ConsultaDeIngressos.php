<?php

declare(strict_types=1);

namespace Lugar\Application\Consulta;

/**
 * Lado de leitura dos ingressos emitidos.
 *
 * A tela do ingresso mostra dados que atravessam ingresso → reserva → lote →
 * evento. Montar isso por repositórios carregaria quatro agregados para exibir
 * seis campos, e nenhuma decisão é tomada no caminho.
 */
interface ConsultaDeIngressos
{
    /**
     * @return list<array<string, mixed>>
     */
    public function daReserva(string $reservaId): array;
}
