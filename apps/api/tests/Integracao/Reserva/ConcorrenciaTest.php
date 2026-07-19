<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Reserva;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Application\Reserva\CriarReserva;
use Lugar\Application\Reserva\CriarReservaComando;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;
use Lugar\Domain\Usuario\UsuarioId;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O TESTE MAIS IMPORTANTE DO REPOSITÓRIO
 *
 * O PRD (§6.1) define o critério de aceite do projeto inteiro:
 *
 *   "existe um teste de integração que dispara N requisições concorrentes
 *    contra um lote com estoque 1 e prova que exatamente uma vence e N−1
 *    recebem 409."
 *
 * É este arquivo.
 *
 * POR QUE PROCESSOS DE VERDADE, E NÃO SIMULAÇÃO
 *
 * Um teste que chama o caso de uso duas vezes em sequência, na mesma
 * transação, não prova nada: não há concorrência nenhuma. Um teste com mocks
 * prova menos ainda — prova que o mock faz o que foi mandado fazer.
 *
 * O lock pessimista é um comportamento do PostgreSQL, não do PHP. Para provar
 * que ele funciona é preciso ter conexões SIMULTÂNEAS de verdade disputando a
 * mesma linha. Por isso este teste dispara processos PHP separados, cada um
 * com sua própria conexão, todos programados para atacar no mesmo instante.
 *
 * SE ESTE TESTE FALHAR, O PRODUTO NÃO EXISTE. Vender o mesmo ingresso duas
 * vezes é o único erro que este sistema não pode cometer.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class ConcorrenciaTest extends KernelTestCase
{
    private const int CONCORRENTES = 10;
    private const string EVENTO_ID = 'evento-concorrencia';
    private const string LOTE_ID = 'lote-concorrencia';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->limparBase();
    }

    protected function tearDown(): void
    {
        $this->limparBase();
        parent::tearDown();
    }

    // ── o teste ──────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('PRD §6.1: com estoque 1 e 10 processos concorrentes, exatamente 1 vence')]
    public function exatamenteUmVenceADisputaPeloUltimoIngresso(): void
    {
        $this->criarLoteCom(estoque: 1);

        $resultados = $this->dispararConcorrentes(self::CONCORRENTES, quantidade: 1);

        $vitorias = array_filter($resultados, static fn (string $r): bool => 'OK' === $r);
        $recusas = array_filter($resultados, static fn (string $r): bool => 'ESTOQUE' === $r);

        self::assertCount(
            1,
            $vitorias,
            sprintf(
                'Exatamente uma reserva deveria vencer. Resultados: %s',
                implode(', ', $resultados),
            ),
        );

        self::assertCount(
            self::CONCORRENTES - 1,
            $recusas,
            'Os demais deveriam receber estoque-insuficiente, e não outro erro.',
        );

        // A prova final está no banco, não na contagem de respostas: uma única
        // reserva ativa retendo o único lugar que existia.
        self::assertSame(1, $this->reservasNoBanco());
    }

    #[Test]
    #[TestDox('o invariante se mantém: reservado + vendido nunca ultrapassa o total')]
    public function oInvarianteNuncaEViolado(): void
    {
        // 5 lugares, 10 concorrentes pedindo 2 cada = demanda de 20 para 5.
        $this->criarLoteCom(estoque: 5);

        $resultados = $this->dispararConcorrentes(self::CONCORRENTES, quantidade: 2);

        $vitorias = \count(array_filter($resultados, static fn (string $r): bool => 'OK' === $r));

        // Cabem exatamente 2 reservas de 2 unidades (4 de 5 lugares); a
        // terceira não cabe porque restaria 1 lugar para um pedido de 2.
        self::assertSame(2, $vitorias, sprintf('Resultados: %s', implode(', ', $resultados)));

        $unidadesRetidas = $this->unidadesReservadasNoBanco();

        self::assertLessThanOrEqual(
            5,
            $unidadesRetidas,
            'O invariante reservado + vendido <= total foi violado.',
        );
        self::assertSame(4, $unidadesRetidas);
    }

    #[Test]
    #[TestDox('o CHECK do banco recusa a escrita mesmo se a aplicação falhar')]
    public function oBancoEAUltimaLinhaDeDefesa(): void
    {
        $this->criarLoteCom(estoque: 10);

        $conexao = self::getContainer()->get(Connection::class);
        \assert($conexao instanceof Connection);

        // Simula o pior caso: alguém contornando a aplicação inteira e
        // gravando um estado impossível direto no banco.
        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->executeStatement(
            'UPDATE lote SET quantidade_vendida = 11 WHERE id = :id',
            ['id' => self::LOTE_ID],
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    /**
     * Dispara N processos PHP independentes, cada um com sua conexão, todos
     * sincronizados para atacar no MESMO instante.
     *
     * A sincronização é o detalhe que faz o teste valer: sem ela, os
     * processos chegariam espaçados por dezenas de milissegundos — tempo de
     * sobra para um terminar antes do outro começar, e a disputa nunca
     * aconteceria de verdade.
     *
     * @return list<string> um resultado por processo: OK, ESTOQUE ou ERRO:...
     */
    private function dispararConcorrentes(int $quantos, int $quantidade): array
    {
        // Instante futuro comum: todos esperam até ele antes de tentar.
        $largada = microtime(true) + 1.5;

        $processos = [];
        $saidas = [];

        for ($i = 0; $i < $quantos; ++$i) {
            $comando = sprintf(
                'php %s %s %s %d %s 2>&1',
                escapeshellarg(__DIR__.'/concorrente.php'),
                escapeshellarg(self::LOTE_ID),
                escapeshellarg(sprintf('comprador%d@email.com', $i)),
                $quantidade,
                escapeshellarg((string) $largada),
            );

            $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processo = proc_open($comando, $descritores, $canos, \dirname(__DIR__, 3));

            if (!\is_resource($processo)) {
                self::fail('Não foi possível iniciar o processo concorrente.');
            }

            $processos[$i] = ['processo' => $processo, 'canos' => $canos];
        }

        foreach ($processos as $i => $p) {
            $saida = trim((string) stream_get_contents($p['canos'][1]));
            fclose($p['canos'][1]);
            fclose($p['canos'][2]);
            proc_close($p['processo']);

            $saidas[$i] = $saida;
        }

        return array_values($saidas);
    }

    private function criarLoteCom(int $estoque): void
    {
        $eventos = self::getContainer()->get(RepositorioDeEventos::class);
        $lotes = self::getContainer()->get(RepositorioDeLotes::class);
        \assert($eventos instanceof RepositorioDeEventos);
        \assert($lotes instanceof RepositorioDeLotes);

        $usuarios = self::getContainer()->get(RepositorioDeUsuarios::class);
        \assert($usuarios instanceof RepositorioDeUsuarios);
        $hash = self::getContainer()->get(HashDeSenha::class);
        \assert($hash instanceof HashDeSenha);

        $organizador = Usuario::cadastrar(
            new UsuarioId('organizador-concorrencia'),
            'organizador@email.com',
            'senha-bem-longa',
            'Rafael',
            $hash,
            new \DateTimeImmutable(),
            [Papel::ORGANIZADOR],
        );
        $usuarios->salvar($organizador);

        $evento = Evento::criar(
            new EventoId(self::EVENTO_ID),
            $organizador->id,
            'Evento de concorrência',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $evento->publicar();
        $eventos->salvar($evento);

        $lotes->salvar(new Lote(
            new LoteId(self::LOTE_ID),
            new EventoId(self::EVENTO_ID),
            'Lote único',
            Dinheiro::emCentavos(22_000),
            $estoque,
            0,
            Periodo::de(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+30 days')),
        ));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
    }

    private function conexao(): Connection
    {
        $conexao = self::getContainer()->get(Connection::class);
        \assert($conexao instanceof Connection);

        return $conexao;
    }

    private function reservasNoBanco(): int
    {
        $total = $this->conexao()->executeQuery(
            'SELECT COUNT(*) FROM reserva WHERE lote_id = :lote',
            ['lote' => self::LOTE_ID],
        )->fetchOne();

        return is_numeric($total) ? (int) $total : 0;
    }

    private function unidadesReservadasNoBanco(): int
    {
        $unidades = $this->conexao()->executeQuery(
            "SELECT COALESCE(SUM(quantidade), 0) FROM reserva
              WHERE lote_id = :lote AND status = 'PENDENTE' AND expira_em > NOW()",
            ['lote' => self::LOTE_ID],
        )->fetchOne();

        return is_numeric($unidades) ? (int) $unidades : 0;
    }

    private function limparBase(): void
    {
        $conexao = $this->conexao();
        $conexao->executeStatement('DELETE FROM ingresso');
        $conexao->executeStatement('DELETE FROM reserva');
        $conexao->executeStatement('DELETE FROM lote');
        $conexao->executeStatement('DELETE FROM evento');
        $conexao->executeStatement('DELETE FROM usuario');
    }
}
