<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso;

use Lugar\Domain\Ingresso\Excecao\IngressoJaUtilizado;
use Lugar\Domain\Reserva\ReservaId;

/**
 * Raiz de agregado. Criado apenas quando a reserva é confirmada (RN-08): um
 * ingresso por unidade da reserva.
 */
class Ingresso
{
    private function __construct(
        public readonly IngressoId $id,
        public readonly ReservaId $reservaId,
        public readonly CodigoIngresso $codigo,
        public readonly \DateTimeImmutable $emitidoEm,
        private StatusDoIngresso $status,
        private ?\DateTimeImmutable $utilizadoEm,
    ) {
    }

    public static function emitir(
        IngressoId $id,
        ReservaId $reservaId,
        CodigoIngresso $codigo,
        \DateTimeImmutable $agora,
    ): self {
        return new self($id, $reservaId, $codigo, $agora, StatusDoIngresso::EMITIDO, null);
    }

    /**
     * RN-10: um ingresso só entra uma vez. A segunda leitura recusa e informa
     * o horário da primeira.
     *
     * A informação do horário não é enfeite: na porta, ela distingue "alguém
     * clonou o print do seu ingresso" de "você já entrou e esqueceu".
     */
    public function utilizar(\DateTimeImmutable $agora): void
    {
        if (StatusDoIngresso::UTILIZADO === $this->status) {
            \assert(null !== $this->utilizadoEm);

            throw new IngressoJaUtilizado($this->utilizadoEm);
        }

        if (StatusDoIngresso::CANCELADO === $this->status) {
            throw new \DomainException('Ingresso cancelado.');
        }

        $this->status = StatusDoIngresso::UTILIZADO;
        $this->utilizadoEm = $agora;
    }

    public function foiUtilizado(): bool
    {
        return StatusDoIngresso::UTILIZADO === $this->status;
    }

    public function status(): StatusDoIngresso
    {
        return $this->status;
    }

    public function utilizadoEm(): ?\DateTimeImmutable
    {
        return $this->utilizadoEm;
    }
}
