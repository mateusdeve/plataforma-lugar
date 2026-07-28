<?php

declare(strict_types=1);

namespace Lugar\Application\Consulta;

/**
 * Lado de LEITURA do organizador, pelo mesmo motivo da `ConsultaDeEventos`:
 * o painel é uma agregação sobre evento, lote, reserva e usuário que não
 * pertence a nenhum agregado.
 *
 * Montá-lo por repositórios exigiria carregar todos os lotes e todas as
 * reservas do evento na memória do PHP para somar cinco números. O banco soma
 * isso sem trazer nada — e a soma não toma decisão nenhuma, então não há
 * invariante em risco.
 *
 * ATENÇÃO: nenhum método aqui verifica permissão, de propósito. Autorização é
 * decidida pelo `EventoVoter` antes da chamada. Uma consulta que também filtra
 * por dono esconde a checagem dentro do SQL — e um `WHERE` esquecido numa
 * consulta futura vira vazamento de dados que nenhum teste de autorização pega,
 * porque a rota "tem" a checagem.
 */
interface ConsultaDoOrganizador
{
    /**
     * Os eventos de um organizador, para a lista do painel.
     *
     * @return list<array<string, mixed>>
     */
    public function eventosDe(string $organizadorId): array;

    /**
     * O painel de um evento: números, ocupação por lote e compradores.
     *
     * @return array<string, mixed>|null null quando o evento não existe
     */
    public function painel(string $eventoId): ?array;

    /**
     * Quem está escalado na porta deste evento (fase 6.4), com nome e e-mail
     * — a tela da escala mostra pessoas, não ids.
     *
     * @return list<array{id: string, nome: string, email: string}>
     */
    public function operadores(string $eventoId): array;
}
