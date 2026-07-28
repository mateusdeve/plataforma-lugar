<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Consulta;

use Doctrine\DBAL\Connection;
use Lugar\Application\Consulta\ConsultaDoOrganizador;
use Lugar\Domain\Reserva\StatusDaReserva;

/**
 * O painel do organizador, em SQL.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A MESMA VERDADE DE ESTOQUE, EM OUTRO ÂNGULO
 *
 * "Reservados agora" e "disponíveis" saem da query do ADR-002 — `status =
 * 'PENDENTE' AND expira_em > NOW()` — a mesma que o lock usa na hora de decidir
 * uma reserva. Não existe contador desnormalizado a consultar: a correção do
 * PLAN.md §2 tirou `quantidade_reservada` do modelo justamente para que este
 * painel não pudesse mostrar um número diferente do que o checkout aplica.
 *
 * Se algum dia esta leitura doer, o caminho é cache com medição na mão — nunca
 * uma segunda coluna que só pode dessincronizar.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class ConsultaDoctrineDoOrganizador implements ConsultaDoOrganizador
{
    public function __construct(private Connection $conexao)
    {
    }

    public function eventosDe(string $organizadorId): array
    {
        /*
          A receita sai das RESERVAS, e não de `vendidos × preço_atual`.

          A tentação é somar pelo lote, que já está no JOIN. Mas o preço do lote
          muda com o tempo — é a razão de existir lote — e quem comprou no 1º
          lote pagou o preço do 1º lote. Multiplicar pelo preço de hoje
          reescreveria o passado, e faria o faturamento de um evento encerrado
          mudar sozinho no dia em que alguém editasse o preço.

          É também a mesma fonte que o painel usa. Duas telas do mesmo evento
          com receitas diferentes é pior que qualquer uma das duas estar errada.
        */
        $sql = <<<SQL
            SELECT e.id,
                   e.titulo,
                   e.local,
                   e.cidade,
                   e.inicia_em,
                   e.status,
                   COALESCE(SUM(l.quantidade_vendida), 0) AS vendidos,
                   COALESCE(SUM(l.quantidade_total), 0) AS capacidade,
                   COALESCE((
                       SELECT SUM(r.total_centavos)
                         FROM reserva r
                         JOIN lote rl ON rl.id = r.lote_id
                        WHERE rl.evento_id = e.id
                          AND r.status = :confirmada
                   ), 0) AS receita_centavos
              FROM evento e
              LEFT JOIN lote l ON l.evento_id = e.id
             WHERE e.organizador_id = :organizador
             GROUP BY e.id, e.titulo, e.local, e.cidade, e.inicia_em, e.status
             ORDER BY e.inicia_em
            SQL;

        return array_map(
            fn (array $linha): array => [
                'id' => $this->texto($linha['id']),
                'titulo' => $this->texto($linha['titulo']),
                'local' => $this->texto($linha['local']),
                'cidade' => $this->texto($linha['cidade']),
                'iniciaEm' => $this->iso($linha['inicia_em']),
                'status' => $this->texto($linha['status']),
                'vendidos' => $this->inteiro($linha['vendidos']),
                'capacidade' => $this->inteiro($linha['capacidade']),
                'receita' => $this->dinheiro($linha['receita_centavos']),
            ],
            $this->conexao
                ->executeQuery($sql, [
                    'organizador' => $organizadorId,
                    'confirmada' => StatusDaReserva::CONFIRMADA->value,
                ])
                ->fetchAllAssociative(),
        );
    }

    public function painel(string $eventoId): ?array
    {
        $evento = $this->conexao->executeQuery(
            'SELECT id, titulo, local, cidade, inicia_em, status FROM evento WHERE id = :id',
            ['id' => $eventoId],
        )->fetchAssociative();

        if (false === $evento) {
            return null;
        }

        $lotes = $this->lotesDo($eventoId);
        $totais = $this->totaisDe($eventoId);

        return [
            'evento' => [
                'id' => $this->texto($evento['id']),
                'titulo' => $this->texto($evento['titulo']),
                'local' => $this->texto($evento['local']),
                'cidade' => $this->texto($evento['cidade']),
                'iniciaEm' => $this->iso($evento['inicia_em']),
                'status' => $this->texto($evento['status']),
            ],
            'vendidos' => $this->somar($lotes, 'vendidos'),
            'vendidosHoje' => $totais['vendidosHoje'],
            'reservadosAgora' => $totais['reservadosAgora'],
            'disponiveis' => $this->somar($lotes, 'disponivel'),
            'receitaConfirmada' => $this->dinheiro($totais['receitaCentavos']),
            'vendasConfirmadas' => $totais['confirmadas'],
            'conversao' => $this->conversao($totais['confirmadas'], $totais['expiradas']),
            'ocupacaoPorLote' => array_map(
                // `disponivel` serve à soma acima, mas não ao contrato da tela:
                // a barra de ocupação usa vendidos/total. Sai daqui.
                fn (array $l): array => array_diff_key($l, ['disponivel' => null]),
                $lotes,
            ),
            'compradores' => $this->compradoresDe($eventoId),
        ];
    }

    public function operadores(string $eventoId): array
    {
        $sql = <<<'SQL'
            SELECT u.id, u.nome, u.email
              FROM evento_operador eo
              JOIN usuario u ON u.id = eo.usuario_id
             WHERE eo.evento_id = :evento
             ORDER BY eo.criado_em
            SQL;

        return array_map(
            fn (array $linha): array => [
                'id' => $this->texto($linha['id']),
                'nome' => $this->texto($linha['nome']),
                'email' => $this->texto($linha['email']),
            ],
            $this->conexao
                ->executeQuery($sql, ['evento' => $eventoId])
                ->fetchAllAssociative(),
        );
    }

    // ── partes ───────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function lotesDo(string $eventoId): array
    {
        $sql = <<<SQL
            SELECT l.id,
                   l.nome,
                   l.preco_centavos,
                   l.quantidade_total,
                   l.quantidade_vendida,
                   l.vendas_iniciam_em,
                   l.vendas_terminam_em,
                   {$this->disponivelDoLote()} AS disponivel
              FROM lote l
             WHERE l.evento_id = :evento
             ORDER BY l.vendas_iniciam_em, l.preco_centavos
            SQL;

        $linhas = $this->conexao
            ->executeQuery($sql, ['evento' => $eventoId] + $this->parametros())
            ->fetchAllAssociative();

        return array_map(fn (array $linha): array => $this->lote($linha), $linhas);
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return array<string, mixed>
     */
    private function lote(array $linha): array
    {
        $disponivel = $this->inteiro($linha['disponivel']);
        $abre = $this->iso($linha['vendas_iniciam_em']);
        $fecha = null === $linha['vendas_terminam_em'] ? null : $this->iso($linha['vendas_terminam_em']);
        $agora = new \DateTimeImmutable();

        return [
            'loteId' => $this->texto($linha['id']),
            'nome' => $this->texto($linha['nome']),
            'preco' => $this->dinheiro($linha['preco_centavos']),
            'vendidos' => $this->inteiro($linha['quantidade_vendida']),
            'total' => $this->inteiro($linha['quantidade_total']),
            'situacao' => match (true) {
                new \DateTimeImmutable($abre) > $agora => 'EM_BREVE',
                null !== $fecha && new \DateTimeImmutable($fecha) < $agora => 'ENCERRADO',
                0 === $disponivel => 'ESGOTADO',
                default => 'DISPONIVEL',
            },
            'vendasIniciamEm' => $abre,
            'disponivel' => $disponivel,
        ];
    }

    /**
     * Os números que atravessam todos os lotes do evento, numa varredura só.
     *
     * @return array{vendidosHoje: int, reservadosAgora: int, receitaCentavos: int, confirmadas: int, expiradas: int}
     */
    private function totaisDe(string $eventoId): array
    {
        $sql = <<<SQL
            SELECT
                COALESCE(SUM(r.quantidade) FILTER (
                    WHERE r.status = :confirmada AND r.criado_em >= CURRENT_DATE
                ), 0) AS vendidos_hoje,
                COALESCE(SUM(r.quantidade) FILTER (
                    WHERE r.status = :pendente AND r.expira_em > NOW()
                ), 0) AS reservados_agora,
                COALESCE(SUM(r.total_centavos) FILTER (WHERE r.status = :confirmada), 0) AS receita_centavos,
                COUNT(*) FILTER (WHERE r.status = :confirmada) AS confirmadas,
                COUNT(*) FILTER (WHERE r.status = :expirada) AS expiradas
              FROM reserva r
              JOIN lote l ON l.id = r.lote_id
             WHERE l.evento_id = :evento
            SQL;

        $linha = $this->conexao->executeQuery($sql, [
            'evento' => $eventoId,
            'pendente' => StatusDaReserva::PENDENTE->value,
            'confirmada' => StatusDaReserva::CONFIRMADA->value,
            'expirada' => StatusDaReserva::EXPIRADA->value,
        ])->fetchAssociative();

        $linha = false === $linha ? [] : $linha;

        return [
            'vendidosHoje' => $this->inteiro($linha['vendidos_hoje'] ?? 0),
            'reservadosAgora' => $this->inteiro($linha['reservados_agora'] ?? 0),
            'receitaCentavos' => $this->inteiro($linha['receita_centavos'] ?? 0),
            'confirmadas' => $this->inteiro($linha['confirmadas'] ?? 0),
            'expiradas' => $this->inteiro($linha['expiradas'] ?? 0),
        ];
    }

    /**
     * PRD §6.5: conversão reserva → venda e taxa de expiração.
     *
     * O denominador conta só reservas que já CHEGARAM a um desfecho. Incluir as
     * pendentes faria a conversão despencar toda vez que muita gente estivesse
     * no checkout ao mesmo tempo — e subir sozinha dez minutos depois, sem que
     * nada tivesse acontecido. Seria uma métrica que mede o instante da
     * consulta, não o produto.
     *
     * @return array{viraramVenda: int, expiraram: int}
     */
    private function conversao(int $confirmadas, int $expiradas): array
    {
        $desfechos = $confirmadas + $expiradas;

        if (0 === $desfechos) {
            return ['viraramVenda' => 0, 'expiraram' => 0];
        }

        $venda = (int) round($confirmadas / $desfechos * 100);

        // Os dois somam 100 por construção: a barra da tela pinta um dos lados
        // e deixa o resto, e dois arredondamentos independentes deixariam uma
        // fresta de 1% entre eles.
        return ['viraramVenda' => $venda, 'expiraram' => 100 - $venda];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compradoresDe(string $eventoId): array
    {
        /*
          O LEFT JOIN com `usuario` é o que ADR-004 tornou possível e opcional
          ao mesmo tempo: comprador logado tem conta e tem nome; convidado tem
          só o e-mail, e continua sendo um comprador legítimo. `nome` vem nulo
          nesse caso, e a tela mostra o e-mail.
        */
        $sql = <<<SQL
            SELECT r.id,
                   r.comprador_email,
                   r.quantidade,
                   r.status,
                   r.expira_em,
                   l.nome AS lote_nome,
                   u.nome AS comprador_nome
              FROM reserva r
              JOIN lote l ON l.id = r.lote_id
              LEFT JOIN usuario u ON u.email = r.comprador_email
             WHERE l.evento_id = :evento
             ORDER BY r.criado_em DESC
             LIMIT 200
            SQL;

        $linhas = $this->conexao
            ->executeQuery($sql, ['evento' => $eventoId])
            ->fetchAllAssociative();

        return array_map(
            fn (array $linha): array => [
                'reservaId' => $this->texto($linha['id']),
                'nome' => null === $linha['comprador_nome'] ? null : $this->texto($linha['comprador_nome']),
                'email' => $this->texto($linha['comprador_email']),
                'loteNome' => $this->texto($linha['lote_nome']),
                'quantidade' => $this->inteiro($linha['quantidade']),
                'status' => $this->texto($linha['status']),
                // Instante absoluto, não "07:41": o texto pronto envelhece no
                // caminho até a tela, e quem formata é quem está desenhando.
                'expiraEm' => StatusDaReserva::PENDENTE->value === $linha['status']
                    ? $this->iso($linha['expira_em'])
                    : null,
            ],
            $linhas,
        );
    }

    // ── SQL compartilhado ────────────────────────────────────────────────

    /** A disponibilidade do ADR-002: total − vendida − reservas ativas. */
    private function disponivelDoLote(): string
    {
        return <<<'SQL'
            GREATEST(
                l.quantidade_total - l.quantidade_vendida - COALESCE((
                    SELECT SUM(r.quantidade)
                      FROM reserva r
                     WHERE r.lote_id = l.id
                       AND r.status = :pendente
                       AND r.expira_em > NOW()
                ), 0),
                0
            )
            SQL;
    }

    /**
     * @return array<string, string>
     */
    private function parametros(): array
    {
        return ['pendente' => StatusDaReserva::PENDENTE->value];
    }

    // ── formatação ───────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $lotes
     */
    private function somar(array $lotes, string $campo): int
    {
        $total = 0;

        foreach ($lotes as $lote) {
            $total += $this->inteiro($lote[$campo] ?? 0);
        }

        return $total;
    }

    /**
     * @return array{centavos: int, moeda: string}
     */
    private function dinheiro(mixed $centavos): array
    {
        return ['centavos' => $this->inteiro($centavos), 'moeda' => 'BRL'];
    }

    private function texto(mixed $valor): string
    {
        return \is_scalar($valor) ? (string) $valor : '';
    }

    private function inteiro(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    private function iso(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(\DATE_ATOM);
        }

        return (new \DateTimeImmutable($this->texto($valor)))->format(\DATE_ATOM);
    }
}
