<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

use Lugar\Domain\Evento\Excecao\EventoComVendasNaoPodeSerExcluido;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Usuario\UsuarioId;

/**
 * Raiz de agregado. Dados de apresentação, data, local e status de publicação.
 * NÃO guarda estoque — isso é do Lote (ADR-001).
 */
class Evento
{
    private function __construct(
        public readonly EventoId $id,
        /**
         * Quem criou o evento. É o vínculo que o EventoVoter consulta — papel
         * ROLE_ORGANIZADOR diz que pode organizar, este campo diz o quê
         * (ADR-004).
         */
        public readonly UsuarioId $organizadorId,
        private string $titulo,
        private string $local,
        private string $cidade,
        private \DateTimeImmutable $iniciaEm,
        private string $descricao,
        private int $prazoReservaMinutos,
        private StatusDoEvento $status,
    ) {
    }

    public static function criar(
        EventoId $id,
        UsuarioId $organizadorId,
        string $titulo,
        string $local,
        string $cidade,
        \DateTimeImmutable $iniciaEm,
        string $descricao = '',
        int $prazoReservaMinutos = Reserva::PRAZO_PADRAO_MINUTOS,
    ): self {
        if ('' === trim($titulo)) {
            throw new \InvalidArgumentException('O evento precisa de um título.');
        }

        self::garantirPrazoValido($prazoReservaMinutos);

        return new self(
            $id,
            $organizadorId,
            $titulo,
            $local,
            $cidade,
            $iniciaEm,
            $descricao,
            $prazoReservaMinutos,
            StatusDoEvento::RASCUNHO,
        );
    }

    public function publicar(): void
    {
        if (StatusDoEvento::CANCELADO === $this->status) {
            throw new \DomainException('Um evento cancelado não pode ser publicado.');
        }

        $this->status = StatusDoEvento::PUBLICADO;
    }

    public function cancelar(): void
    {
        $this->status = StatusDoEvento::CANCELADO;
    }

    /**
     * RN-12: evento com qualquer venda confirmada não pode ser excluído.
     *
     * O motivo não é técnico, é humano: existem pessoas com ingresso na mão.
     * Cancelar preserva o rastro — quem comprou, quanto pagou, o que aconteceu.
     * Excluir apagaria a prova de uma transação que existiu de verdade.
     */
    public function garantirQuePodeSerExcluido(int $vendasConfirmadas): void
    {
        if ($vendasConfirmadas > 0) {
            throw new EventoComVendasNaoPodeSerExcluido(
                sprintf(
                    'Este evento tem %d venda(s) confirmada(s). Cancele em vez de excluir.',
                    $vendasConfirmadas,
                ),
            );
        }
    }

    /** RN-01: o prazo da reserva é configurável por evento, entre 5 e 30 min. */
    public function alterarPrazoDeReserva(int $minutos): void
    {
        self::garantirPrazoValido($minutos);

        $this->prazoReservaMinutos = $minutos;
    }

    private static function garantirPrazoValido(int $minutos): void
    {
        if ($minutos < Reserva::PRAZO_MINIMO_MINUTOS || $minutos > Reserva::PRAZO_MAXIMO_MINUTOS) {
            throw new \InvalidArgumentException(
                sprintf(
                    'O prazo da reserva deve ficar entre %d e %d minutos.',
                    Reserva::PRAZO_MINIMO_MINUTOS,
                    Reserva::PRAZO_MAXIMO_MINUTOS,
                ),
            );
        }
    }

    /** O vínculo que autoriza — não o papel. Ver ADR-004. */
    public function pertenceA(UsuarioId $usuarioId): bool
    {
        return $this->organizadorId->ehIgualA($usuarioId);
    }

    public function estaPublicado(): bool
    {
        return StatusDoEvento::PUBLICADO === $this->status;
    }

    public function status(): StatusDoEvento
    {
        return $this->status;
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    public function prazoReservaMinutos(): int
    {
        return $this->prazoReservaMinutos;
    }

    public function iniciaEm(): \DateTimeImmutable
    {
        return $this->iniciaEm;
    }

    public function local(): string
    {
        return $this->local;
    }

    public function cidade(): string
    {
        return $this->cidade;
    }

    public function descricao(): string
    {
        return $this->descricao;
    }
}
