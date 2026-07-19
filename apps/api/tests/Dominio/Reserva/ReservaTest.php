<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Reserva;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Reserva\Excecao\ReservaNaoEstaAtiva;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Reserva\StatusDaReserva;
use Lugar\Tests\Dominio\Apoio\RelogioParado;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras da reserva: RN-01, RN-02 e RN-07, mais a máquina de estados.
 *
 * Todo teste que envolve tempo usa RelogioParado. É o que torna possível
 * verificar "expira em 10 minutos" sem esperar 10 minutos — e o que faz o
 * resultado ser o mesmo hoje, amanhã e no CI.
 */
final class ReservaTest extends TestCase
{
    private function reserva(
        RelogioParado $relogio,
        int $quantidade = 2,
        int $prazo = Reserva::PRAZO_PADRAO_MINUTOS,
    ): Reserva {
        return Reserva::criar(
            new ReservaId('reserva-1'),
            new LoteId('lote-1'),
            'ana@email.com',
            $quantidade,
            Dinheiro::emCentavos(22_000)->multiplicadoPor($quantidade),
            $relogio->agora(),
            $prazo,
        );
    }

    // ── RN-01 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-01: a reserva expira 10 minutos depois de criada')]
    public function expiraEmDezMinutos(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');

        $reserva = $this->reserva($relogio);

        self::assertSame('2026-07-15 12:10:00', $reserva->expiraEm->format('Y-m-d H:i:s'));
    }

    #[Test]
    #[TestDox('RN-01: o prazo é configurável entre 5 e 30 minutos')]
    public function prazoConfiguravelDentroDosLimites(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');

        self::assertSame(
            '2026-07-15 12:05:00',
            $this->reserva($relogio, prazo: 5)->expiraEm->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-07-15 12:30:00',
            $this->reserva($relogio, prazo: 30)->expiraEm->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    #[TestDox('RN-01: prazo fora de 5..30 é recusado')]
    public function prazoForaDosLimitesERecusado(): void
    {
        $relogio = new RelogioParado();

        $this->expectException(\InvalidArgumentException::class);

        $this->reserva($relogio, prazo: 31);
    }

    // ── RN-02 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-02: ativa é PENDENTE e dentro do prazo')]
    public function nasceAtiva(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');

        $reserva = $this->reserva($relogio);

        self::assertTrue($reserva->estaAtiva($relogio->agora()));
        self::assertSame(StatusDaReserva::PENDENTE, $reserva->status());
    }

    #[Test]
    #[TestDox('RN-02: deixa de reter estoque no instante em que vence, sem job nenhum')]
    public function deixaDeSerAtivaAoVencer(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);

        $relogio->avancar('+9 minutes 59 seconds');
        self::assertTrue($reserva->estaAtiva($relogio->agora()));

        $relogio->avancar('+1 second');

        // Nada foi processado, nenhum cron rodou, o status no banco continua
        // PENDENTE — e a reserva já não retém estoque. É o ADR-002 em uma
        // asserção.
        self::assertFalse($reserva->estaAtiva($relogio->agora()));
        self::assertTrue($reserva->expirou($relogio->agora()));
        self::assertSame(StatusDaReserva::PENDENTE, $reserva->status());
    }

    #[Test]
    #[TestDox('RN-02: os segundos restantes vêm do servidor e zeram ao vencer')]
    public function segundosRestantesRefletemOPrazo(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);

        self::assertSame(600, $reserva->segundosRestantes($relogio->agora()));

        $relogio->avancar('+1 minute 13 seconds');
        self::assertSame(527, $reserva->segundosRestantes($relogio->agora()));

        $relogio->avancar('+1 hour');
        self::assertSame(0, $reserva->segundosRestantes($relogio->agora()));
    }

    // ── RN-07 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-07: confirmar uma reserva ativa funciona')]
    public function confirmaReservaAtiva(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);

        $relogio->avancar('+3 minutes');
        $reserva->confirmar($relogio->agora());

        self::assertSame(StatusDaReserva::CONFIRMADA, $reserva->status());
    }

    #[Test]
    #[TestDox('RN-07: confirmar reserva vencida é recusado com type=reserva-expirada')]
    public function naoConfirmaReservaVencida(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);

        $relogio->avancar('+11 minutes');

        try {
            $reserva->confirmar($relogio->agora());
            self::fail('Deveria ter lançado ReservaNaoEstaAtiva.');
        } catch (ReservaNaoEstaAtiva $erro) {
            // O `type` que o front usa para escolher a TELA de expiração, e
            // não o banner de estoque. Os dois são 409.
            self::assertSame('reserva-expirada', $erro->tipo());
        }
    }

    // ── máquina de estados ───────────────────────────────────────────────

    #[Test]
    #[TestDox('os estados finais são terminais: confirmada não volta atrás')]
    public function confirmadaEhTerminal(): void
    {
        $relogio = new RelogioParado();
        $reserva = $this->reserva($relogio);
        $reserva->confirmar($relogio->agora());

        self::assertTrue($reserva->status()->ehTerminal());

        $this->expectException(ReservaNaoEstaAtiva::class);
        $reserva->cancelar($relogio->agora());
    }

    #[Test]
    #[TestDox('cancelada não pode ser confirmada depois')]
    public function canceladaNaoConfirma(): void
    {
        $relogio = new RelogioParado();
        $reserva = $this->reserva($relogio);
        $reserva->cancelar($relogio->agora());

        $this->expectException(ReservaNaoEstaAtiva::class);
        $reserva->confirmar($relogio->agora());
    }

    #[Test]
    #[TestDox('marcar como expirada é higiene de dados, não correção de estoque')]
    public function marcarComoExpiradaSoValeParaVencida(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);

        // Ainda dentro do prazo: marcar seria mentira.
        try {
            $reserva->marcarComoExpirada($relogio->agora());
            self::fail('Não deveria marcar como expirada uma reserva ativa.');
        } catch (\LogicException) {
            // esperado
        }

        $relogio->avancar('+11 minutes');
        $reserva->marcarComoExpirada($relogio->agora());

        self::assertSame(StatusDaReserva::EXPIRADA, $reserva->status());
    }

    #[Test]
    #[TestDox('uma reserva expirada nunca é reaproveitada — cria-se outra')]
    public function expiradaNaoRessuscita(): void
    {
        $relogio = new RelogioParado('2026-07-15 12:00:00');
        $reserva = $this->reserva($relogio);
        $relogio->avancar('+11 minutes');
        $reserva->marcarComoExpirada($relogio->agora());

        // Não existe reativar() nem estender(): enquanto o prazo corria o
        // estoque ficou retido; ao vencer, ele voltou ao público e pode já ter
        // sido levado. Ressuscitar venderia um lugar que talvez não exista.
        self::assertFalse(method_exists($reserva, 'reativar'));
        self::assertFalse(method_exists($reserva, 'estender'));

        $this->expectException(ReservaNaoEstaAtiva::class);
        $reserva->confirmar($relogio->agora());
    }

    #[Test]
    #[TestDox('e-mail inválido é recusado na criação')]
    public function emailInvalidoERecusado(): void
    {
        $relogio = new RelogioParado();

        $this->expectException(\InvalidArgumentException::class);

        Reserva::criar(
            new ReservaId('r1'),
            new LoteId('l1'),
            'nao-e-email',
            1,
            Dinheiro::emCentavos(100),
            $relogio->agora(),
        );
    }
}
