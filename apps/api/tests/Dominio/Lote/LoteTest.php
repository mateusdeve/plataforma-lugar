<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Lote;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Domain\Lote\Excecao\ForaDaJanelaDeVenda;
use Lugar\Domain\Lote\Excecao\QuantidadeInvalida;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras do estoque: RN-03, RN-04, RN-06 e RN-11.
 *
 * Nenhum banco, nenhum framework, nenhum I/O. A suíte inteira roda em
 * milissegundos, que é o que permite rodá-la a cada gravação de arquivo.
 */
final class LoteTest extends TestCase
{
    private const string AGORA = '2026-07-15 12:00:00';

    private function lote(int $total = 100, int $vendida = 0, ?Periodo $janela = null): Lote
    {
        return new Lote(
            new LoteId('lote-1'),
            new EventoId('evento-1'),
            '2º lote',
            Dinheiro::emCentavos(22_000),
            $total,
            $vendida,
            $janela ?? Periodo::de(
                new \DateTimeImmutable('2026-07-01 00:00:00'),
                new \DateTimeImmutable('2026-07-31 23:59:59'),
            ),
        );
    }

    private function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AGORA);
    }

    // ── RN-03 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-03: disponível desconta vendidos e reservas ativas')]
    public function disponivelDescontaVendidosEReservados(): void
    {
        $lote = $this->lote(total: 100, vendida: 60);

        // 100 total − 60 vendidos − 10 reservados agora = 30
        self::assertSame(30, $lote->disponivel(reservadasAtivas: 10));
    }

    #[Test]
    #[TestDox('RN-03: não é possível reservar mais que o disponível')]
    public function naoReservaAlemDoDisponivel(): void
    {
        $lote = $this->lote(total: 10, vendida: 8);

        $this->expectException(EstoqueInsuficiente::class);

        // Restam 2 (10 − 8 − 0). Pedir 3 tem de falhar.
        $lote->garantirQuePodeReservar(3, reservadasAtivas: 0, agora: $this->agora());
    }

    #[Test]
    #[TestDox('RN-03: reservas ativas de outras pessoas reduzem o disponível')]
    public function reservasAtivasDeTerceirosBloqueiam(): void
    {
        $lote = $this->lote(total: 10, vendida: 8);

        $this->expectException(EstoqueInsuficiente::class);

        // Restam 2, mas 2 já estão retidos por outra reserva ativa → 0.
        $lote->garantirQuePodeReservar(1, reservadasAtivas: 2, agora: $this->agora());
    }

    #[Test]
    #[TestDox('RN-03: o último ingresso pode ser reservado')]
    public function oUltimoIngressoPodeSerReservado(): void
    {
        $lote = $this->lote(total: 10, vendida: 9);

        $lote->garantirQuePodeReservar(1, reservadasAtivas: 0, agora: $this->agora());

        // Sem exceção: o caminho de sucesso do caso mais disputado do sistema.
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[TestDox('a exceção de estoque carrega quanto havia e quanto foi pedido')]
    public function aExcecaoInformaDisponivelESolicitado(): void
    {
        $lote = $this->lote(total: 10, vendida: 8);

        try {
            $lote->garantirQuePodeReservar(5, reservadasAtivas: 0, agora: $this->agora());
            self::fail('Deveria ter lançado EstoqueInsuficiente.');
        } catch (EstoqueInsuficiente $erro) {
            self::assertSame(2, $erro->disponivel);
            self::assertSame(5, $erro->solicitado);
            // É este `tipo` que vira o campo `type` do problem+json e faz o
            // front escolher o banner certo, sem ler a mensagem.
            self::assertSame('estoque-insuficiente', $erro->tipo());
        }
    }

    // ── RN-04 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-04: no máximo 6 ingressos por reserva')]
    public function maximoDeSeisPorReserva(): void
    {
        $lote = $this->lote(total: 100);

        $lote->garantirQuePodeReservar(6, reservadasAtivas: 0, agora: $this->agora());

        $this->expectException(QuantidadeInvalida::class);
        $lote->garantirQuePodeReservar(7, reservadasAtivas: 0, agora: $this->agora());
    }

    #[Test]
    #[TestDox('RN-04: reservar zero ou menos não faz sentido')]
    public function quantidadeTemDeSerPositiva(): void
    {
        $this->expectException(QuantidadeInvalida::class);

        $this->lote()->garantirQuePodeReservar(0, reservadasAtivas: 0, agora: $this->agora());
    }

    // ── RN-06 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-06: não se reserva antes da janela de venda abrir')]
    public function naoReservaAntesDaJanela(): void
    {
        $lote = $this->lote(janela: Periodo::aPartirDe(new \DateTimeImmutable('2026-08-01 00:00:00')));

        $this->expectException(ForaDaJanelaDeVenda::class);

        $lote->garantirQuePodeReservar(1, reservadasAtivas: 0, agora: $this->agora());
    }

    #[Test]
    #[TestDox('RN-06: não se reserva depois da janela fechar')]
    public function naoReservaDepoisDaJanela(): void
    {
        $lote = $this->lote(janela: Periodo::de(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-06-30 23:59:59'),
        ));

        $this->expectException(ForaDaJanelaDeVenda::class);

        $lote->garantirQuePodeReservar(1, reservadasAtivas: 0, agora: $this->agora());
    }

    #[Test]
    #[TestDox('RN-06: lote sem data de encerramento continua vendendo')]
    public function janelaSemFimSegueAberta(): void
    {
        $lote = $this->lote(janela: Periodo::aPartirDe(new \DateTimeImmutable('2026-01-01 00:00:00')));

        $lote->garantirQuePodeReservar(1, reservadasAtivas: 0, agora: $this->agora());

        $this->expectNotToPerformAssertions();
    }

    // ── RN-11 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-11: o lote não pode encolher abaixo do que já foi vendido')]
    public function naoEncolheAbaixoDoVendido(): void
    {
        $lote = $this->lote(total: 200, vendida: 150);

        $this->expectException(QuantidadeInvalida::class);

        $lote->redimensionarPara(100);
    }

    #[Test]
    #[TestDox('RN-11: reduzir até o exato número de vendidos é permitido')]
    public function encolherAteOVendidoEPermitido(): void
    {
        $lote = $this->lote(total: 200, vendida: 150);

        $lote->redimensionarPara(150);

        self::assertSame(150, $lote->quantidadeTotal());
        self::assertSame(0, $lote->disponivel(0));
    }

    // ── invariante do banco, espelhado no domínio ─────────────────────────

    #[Test]
    #[TestDox('registrar venda além do total é recusado')]
    public function vendaNaoUltrapassaOTotal(): void
    {
        $lote = $this->lote(total: 10, vendida: 9);

        $this->expectException(EstoqueInsuficiente::class);

        $lote->registrarVenda(2);
    }

    #[Test]
    #[TestDox('lote esgota quando vendidos e reservados somam o total')]
    public function esgotaQuandoNaoSobraNada(): void
    {
        $lote = $this->lote(total: 10, vendida: 8);

        self::assertFalse($lote->esgotou(reservadasAtivas: 1));
        self::assertTrue($lote->esgotou(reservadasAtivas: 2));
    }

    #[Test]
    #[TestDox('um lote não nasce com mais vendidos que o total')]
    public function naoAceitaVendidoMaiorQueTotal(): void
    {
        $this->expectException(QuantidadeInvalida::class);

        $this->lote(total: 10, vendida: 11);
    }
}
