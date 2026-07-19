<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Consulta;

use Doctrine\DBAL\Connection;
use Lugar\Application\Consulta\ConsultaDeEventos;
use Lugar\Domain\Reserva\StatusDaReserva;

/**
 * O lado de leitura, em SQL.
 *
 * A subconsulta de reservas ativas é a query do ADR-002 aplicada à listagem:
 * `status = 'PENDENTE' AND expira_em > NOW()`. É a mesma verdade que o lock
 * usa na hora de decidir — a diferença é que aqui ela pode estar até 5
 * segundos desatualizada (PRD §6.4), e na criação da reserva, nunca.
 */
final readonly class ConsultaDoctrineDeEventos implements ConsultaDeEventos
{
    public function __construct(private Connection $conexao)
    {
    }

    public function publicados(): array
    {
        $sql = <<<SQL
            SELECT e.id,
                   e.titulo,
                   e.local,
                   e.cidade,
                   e.inicia_em,
                   MIN(l.preco_centavos) FILTER (WHERE {$this->loteVendavel()}) AS preco_minimo,
                   COALESCE(SUM({$this->disponivelDoLote()}) FILTER (WHERE {$this->loteVendavel()}), 0) AS disponivel,
                   COUNT(l.id) FILTER (WHERE l.vendas_iniciam_em > NOW()) AS lotes_futuros
              FROM evento e
              LEFT JOIN lote l ON l.evento_id = e.id
             WHERE e.status = 'PUBLICADO'
             GROUP BY e.id, e.titulo, e.local, e.cidade, e.inicia_em
             ORDER BY e.inicia_em
            SQL;

        return array_map(
            fn (array $linha): array => $this->resumo($linha),
            $this->conexao->executeQuery($sql, $this->parametros())->fetchAllAssociative(),
        );
    }

    public function detalhe(string $eventoId): ?array
    {
        $evento = $this->conexao->executeQuery(
            'SELECT id, titulo, local, cidade, inicia_em, descricao, prazo_reserva_minutos, status
               FROM evento WHERE id = :id',
            ['id' => $eventoId],
        )->fetchAssociative();

        if (false === $evento || 'PUBLICADO' !== $evento['status']) {
            return null;
        }

        $sql = <<<SQL
            SELECT l.id,
                   l.nome,
                   l.preco_centavos,
                   l.preco_moeda,
                   l.quantidade_total,
                   l.vendas_iniciam_em,
                   l.vendas_terminam_em,
                   {$this->disponivelDoLote()} AS disponivel
              FROM lote l
             WHERE l.evento_id = :evento
             ORDER BY l.vendas_iniciam_em, l.preco_centavos
            SQL;

        $lotes = $this->conexao
            ->executeQuery($sql, ['evento' => $eventoId] + $this->parametros())
            ->fetchAllAssociative();

        return [
            'id' => $this->texto($evento['id']),
            'titulo' => $this->texto($evento['titulo']),
            'local' => $this->texto($evento['local']),
            'cidade' => $this->texto($evento['cidade']),
            'iniciaEm' => $this->iso($evento['inicia_em']),
            'descricao' => $this->texto($evento['descricao']),
            'prazoReservaMinutos' => $this->inteiro($evento['prazo_reserva_minutos']),
            'situacao' => $this->situacaoDoEvento(
                array_sum(array_map(fn (array $l): int => $this->inteiro($l['disponivel']), $lotes)),
                $this->temLoteFuturo($lotes),
            ),
            'restantes' => null,
            'precoMinimo' => $this->precoMinimoDos($lotes),
            'lotes' => array_map(fn (array $l): array => $this->lote($l), $lotes),
        ];
    }

    // ── SQL compartilhado ────────────────────────────────────────────────

    /**
     * A disponibilidade do ADR-002: total − vendida − reservas ativas.
     *
     * `GREATEST(..., 0)` porque uma reserva pode estar retendo estoque que já
     * foi vendido em outro lote — o resultado nunca deve ficar negativo na tela.
     */
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

    private function loteVendavel(): string
    {
        return 'l.vendas_iniciam_em <= NOW() AND (l.vendas_terminam_em IS NULL OR l.vendas_terminam_em >= NOW())';
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
     * @param array<string, mixed> $linha
     *
     * @return array<string, mixed>
     */
    private function resumo(array $linha): array
    {
        $disponivel = $this->inteiro($linha['disponivel']);
        $temFuturo = ($this->inteiro($linha['lotes_futuros'])) > 0;

        return [
            'id' => $this->texto($linha['id']),
            'titulo' => $this->texto($linha['titulo']),
            'local' => $this->texto($linha['local']),
            'cidade' => $this->texto($linha['cidade']),
            'iniciaEm' => $this->iso($linha['inicia_em']),
            'situacao' => $this->situacaoDoEvento($disponivel, $temFuturo),
            // O design mostra "Últimos 6" quando resta pouco — o número exato
            // só aparece nesse caso, e não em toda listagem.
            'restantes' => $disponivel > 0 && $disponivel <= 10 ? $disponivel : null,
            'precoMinimo' => null === $linha['preco_minimo']
                ? null
                : ['centavos' => $this->inteiro($linha['preco_minimo']), 'moeda' => 'BRL'],
        ];
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return array<string, mixed>
     */
    private function lote(array $linha): array
    {
        $disponivel = $this->inteiro($linha['disponivel']);
        $inicia = $this->iso($linha['vendas_iniciam_em']);
        $termina = null === $linha['vendas_terminam_em'] ? null : $this->iso($linha['vendas_terminam_em']);

        $agora = new \DateTimeImmutable();

        $situacao = match (true) {
            new \DateTimeImmutable($inicia) > $agora => 'EM_BREVE',
            null !== $termina && new \DateTimeImmutable($termina) < $agora => 'ENCERRADO',
            0 === $disponivel => 'ESGOTADO',
            default => 'DISPONIVEL',
        };

        return [
            'id' => $this->texto($linha['id']),
            'nome' => $this->texto($linha['nome']),
            'preco' => [
                'centavos' => $this->inteiro($linha['preco_centavos']),
                'moeda' => $this->texto($linha['preco_moeda']),
            ],
            'disponivel' => $disponivel,
            'situacao' => $situacao,
            'vendasIniciamEm' => $inicia,
            'vendasTerminamEm' => $termina,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lotes
     *
     * @return array{centavos: int, moeda: string}|null
     */
    private function precoMinimoDos(array $lotes): ?array
    {
        $precos = array_map(
            fn (array $l): int => $this->inteiro($l['preco_centavos']),
            array_filter($lotes, fn (array $l): bool => $this->inteiro($l['disponivel']) > 0),
        );

        return [] === $precos ? null : ['centavos' => min($precos), 'moeda' => 'BRL'];
    }

    private function situacaoDoEvento(int $disponivel, bool $temLoteFuturo): string
    {
        return match (true) {
            $disponivel > 10 => 'DISPONIVEL',
            $disponivel > 0 => 'ULTIMOS',
            $temLoteFuturo => 'EM_BREVE',
            default => 'ESGOTADO',
        };
    }

    /**
     * @param list<array<string, mixed>> $lotes
     */
    private function temLoteFuturo(array $lotes): bool
    {
        $agora = new \DateTimeImmutable();

        foreach ($lotes as $lote) {
            if (new \DateTimeImmutable($this->iso($lote['vendas_iniciam_em'])) > $agora) {
                return true;
            }
        }

        return false;
    }

    /**
     * `fetchAllAssociative` devolve array<string, mixed> — o driver não promete
     * tipo. Converter em dois lugares centrais é mais legível que espalhar
     * dezenas de checagens pelo arquivo, e é o que o PHPStan nível 9 exige.
     */
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
