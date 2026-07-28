<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Portaria;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\IngressoId;
use Lugar\Domain\Ingresso\RepositorioDeIngressos;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;
use Lugar\Domain\Usuario\UsuarioId;
use Lugar\Kernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * RN-10 SOB DISPUTA — O IRMÃO DO TESTE DE CONCORRÊNCIA DA FASE 2.
 *
 * `PortariaTest` prova que a segunda leitura é recusada. Prova isso lendo o
 * código duas vezes EM SEQUÊNCIA, o que não é o caso difícil: entre uma
 * requisição e outra a primeira já commitou, e um `if` bobo passaria no teste.
 *
 * O caso difícil é este: o print do ingresso circulou no grupo da família e
 * duas pessoas chegam em catracas DIFERENTES no mesmo instante. Sem
 * `SELECT ... FOR UPDATE`, os dois processos leem `status = EMITIDO`, os dois
 * concluem "pode entrar", e os dois entram.
 *
 * O sintoma não aparece na hora. Aparece na contagem de público no fim da
 * noite, quando não há mais nada a fazer — e é indistinguível de erro de
 * contagem manual.
 *
 * Este teste dispara processos PHP separados, cada um com sua conexão, todos
 * programados para atacar no mesmo instante. É a única forma honesta de provar
 * comportamento do Postgres.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class CatracaConcorrenteTest extends KernelTestCase
{
    private const int CATRACAS = 8;
    private const string EVENTO_ID = 'evento-catraca';
    private const string CODIGO = 'LGR-CTRC-2K9M';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->limparBase();
        $this->montarCenario();
    }

    protected function tearDown(): void
    {
        $this->limparBase();
        parent::tearDown();
    }

    #[Test]
    #[TestDox('RN-10: com 8 catracas lendo o MESMO código ao mesmo tempo, exatamente 1 entra')]
    public function exatamenteUmaPessoaAtravessaAPorta(): void
    {
        $resultados = $this->dispararCatracas(self::CATRACAS);

        $entraram = array_filter($resultados, static fn (string $r): bool => 'ENTROU' === $r);
        $recusados = array_filter($resultados, static fn (string $r): bool => 'JA_USADO' === $r);

        self::assertCount(
            1,
            $entraram,
            sprintf(
                'Exatamente uma leitura deveria passar. Resultados: %s',
                implode(', ', $resultados),
            ),
        );

        self::assertCount(
            self::CATRACAS - 1,
            $recusados,
            sprintf(
                'As demais deveriam ser recusadas por RN-10, e não por outro '
                .'erro. Resultados: %s',
                implode(', ', $resultados),
            ),
        );

        // A prova final está no banco: um ingresso, um horário de uso.
        self::assertSame('UTILIZADO', $this->statusDoIngresso());
        self::assertNotNull(
            $this->utilizadoEm(),
            'O CHECK do banco exige horário quando o status é UTILIZADO.',
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function dispararCatracas(int $quantas): array
    {
        // Instante futuro comum: todas esperam até ele antes de tentar. Sem
        // isso, o custo de boot do Symfony espalharia as leituras no tempo e a
        // disputa nunca aconteceria.
        $largada = microtime(true) + 1.5;

        $processos = [];
        $saidas = [];

        for ($i = 0; $i < $quantas; ++$i) {
            $comando = sprintf(
                'php %s %s %s %s 2>&1',
                escapeshellarg(__DIR__.'/catraca.php'),
                escapeshellarg(self::CODIGO),
                escapeshellarg(self::EVENTO_ID),
                escapeshellarg((string) $largada),
            );

            $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processo = proc_open($comando, $descritores, $canos, \dirname(__DIR__, 3));

            if (!\is_resource($processo)) {
                self::fail('Não foi possível iniciar o processo da catraca.');
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

    private function statusDoIngresso(): string
    {
        $valor = $this->conexao()
            ->executeQuery('SELECT status FROM ingresso WHERE codigo = :c', ['c' => self::CODIGO])
            ->fetchOne();

        return \is_string($valor) ? $valor : '';
    }

    private function utilizadoEm(): ?string
    {
        $valor = $this->conexao()
            ->executeQuery('SELECT utilizado_em FROM ingresso WHERE codigo = :c', ['c' => self::CODIGO])
            ->fetchOne();

        return \is_string($valor) ? $valor : null;
    }

    private function conexao(): Connection
    {
        $conexao = self::getContainer()->get(Connection::class);
        \assert($conexao instanceof Connection);

        return $conexao;
    }

    // ── cenário ──────────────────────────────────────────────────────────

    private function montarCenario(): void
    {
        $hash = self::getContainer()->get(HashDeSenha::class);
        \assert($hash instanceof HashDeSenha);

        $organizador = Usuario::cadastrar(
            new UsuarioId('organizador-catraca'),
            'organizador@catraca.test',
            'senha-bem-longa',
            'Rafael',
            $hash,
            new \DateTimeImmutable(),
            [Papel::ORGANIZADOR],
        );

        $usuarios = self::getContainer()->get(RepositorioDeUsuarios::class);
        \assert($usuarios instanceof RepositorioDeUsuarios);
        $usuarios->salvar($organizador);

        $evento = Evento::criar(
            new EventoId(self::EVENTO_ID),
            $organizador->id,
            'Evento da catraca',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $evento->publicar();

        $eventos = self::getContainer()->get(RepositorioDeEventos::class);
        \assert($eventos instanceof RepositorioDeEventos);
        $eventos->salvar($evento);

        $lotes = self::getContainer()->get(RepositorioDeLotes::class);
        \assert($lotes instanceof RepositorioDeLotes);
        $lotes->salvar(new Lote(
            new LoteId('lote-catraca'),
            $evento->id,
            'Lote único',
            Dinheiro::emCentavos(220_00),
            100,
            1,
            Periodo::de(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+29 days')),
        ));

        $reserva = Reserva::criar(
            new ReservaId('reserva-catraca'),
            new LoteId('lote-catraca'),
            'ana@catraca.test',
            1,
            Dinheiro::emCentavos(220_00),
            new \DateTimeImmutable(),
        );
        $reserva->confirmar(new \DateTimeImmutable('+1 minute'));

        $reservas = self::getContainer()->get(RepositorioDeReservas::class);
        \assert($reservas instanceof RepositorioDeReservas);
        $reservas->salvar($reserva);

        $ingressos = self::getContainer()->get(RepositorioDeIngressos::class);
        \assert($ingressos instanceof RepositorioDeIngressos);
        $ingressos->salvar(Ingresso::emitir(
            new IngressoId('ingresso-catraca'),
            $reserva->id,
            new CodigoIngresso(self::CODIGO),
            new \DateTimeImmutable(),
        ));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
    }

    private function limparBase(): void
    {
        $conexao = $this->conexao();

        foreach (['pagamento', 'ingresso', 'reserva', 'lote', 'evento_operador', 'token_de_renovacao', 'evento', 'usuario'] as $tabela) {
            $conexao->executeStatement(sprintf('DELETE FROM %s', $tabela));
        }
    }
}
