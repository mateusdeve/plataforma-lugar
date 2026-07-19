<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Evento;

use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\Excecao\EventoComVendasNaoPodeSerExcluido;
use Lugar\Domain\Evento\StatusDoEvento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** RN-12 e o prazo configurável da RN-01. */
final class EventoTest extends TestCase
{
    private function evento(): Evento
    {
        return Evento::criar(
            new EventoId('evento-1'),
            'FrontZ Conf 2026',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('2026-09-12 09:00:00'),
        );
    }

    #[Test]
    #[TestDox('RN-12: evento com venda confirmada não pode ser excluído')]
    public function comVendasNaoExclui(): void
    {
        $this->expectException(EventoComVendasNaoPodeSerExcluido::class);

        $this->evento()->garantirQuePodeSerExcluido(vendasConfirmadas: 1);
    }

    #[Test]
    #[TestDox('RN-12: sem vendas, pode ser excluído')]
    public function semVendasExclui(): void
    {
        $this->evento()->garantirQuePodeSerExcluido(vendasConfirmadas: 0);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[TestDox('RN-12: com vendas, o caminho é cancelar')]
    public function comVendasPodeCancelar(): void
    {
        $evento = $this->evento();
        $evento->publicar();

        $evento->cancelar();

        self::assertSame(StatusDoEvento::CANCELADO, $evento->status());
    }

    #[Test]
    #[TestDox('o evento nasce em rascunho e só aparece na vitrine depois de publicado')]
    public function nasceEmRascunho(): void
    {
        $evento = $this->evento();

        self::assertSame(StatusDoEvento::RASCUNHO, $evento->status());
        self::assertFalse($evento->estaPublicado());

        $evento->publicar();
        self::assertTrue($evento->estaPublicado());
    }

    #[Test]
    #[TestDox('evento cancelado não volta a ser publicado')]
    public function canceladoNaoPublica(): void
    {
        $evento = $this->evento();
        $evento->cancelar();

        $this->expectException(\DomainException::class);
        $evento->publicar();
    }

    #[Test]
    #[TestDox('RN-01: o prazo de reserva do evento fica entre 5 e 30 minutos')]
    public function prazoDeReservaTemLimites(): void
    {
        $evento = $this->evento();
        self::assertSame(10, $evento->prazoReservaMinutos());

        $evento->alterarPrazoDeReserva(15);
        self::assertSame(15, $evento->prazoReservaMinutos());

        $this->expectException(\InvalidArgumentException::class);
        $evento->alterarPrazoDeReserva(4);
    }
}
