<?php

declare(strict_types=1);

namespace Lugar\Application\Consulta;

/**
 * Lado de LEITURA, separado dos repositórios de domínio.
 *
 * A vitrine precisa de disponibilidade calculada, preço mínimo e situação
 * agregada — dados que não pertencem a nenhum agregado e que, montados via
 * repositórios, exigiriam carregar todos os lotes e reservas de todos os
 * eventos para exibir uma lista.
 *
 * Aqui é SQL direto devolvendo exatamente o que a tela mostra. Os repositórios
 * de Domain/ continuam existindo para quando há uma DECISÃO a tomar; consulta
 * de leitura não toma decisão nenhuma.
 */
interface ConsultaDeEventos
{
    /**
     * @return list<array<string, mixed>>
     */
    public function publicados(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function detalhe(string $eventoId): ?array;
}
