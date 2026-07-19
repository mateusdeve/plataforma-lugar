<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

/**
 * Refresh token — a sessão longa.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE FICA GUARDADO É O HASH, NÃO O TOKEN
 *
 * O valor em texto puro é entregue ao navegador e nunca mais existe do lado do
 * servidor. Se o banco vazar, o atacante leva hashes — inúteis para se passar
 * por alguém. É o mesmo raciocínio de senha, aplicado a uma credencial que
 * vale 30 dias.
 *
 * ROTAÇÃO A CADA USO
 *
 * Usar um refresh token o invalida e emite outro. Isso transforma roubo de
 * token em algo DETECTÁVEL: se o token furtado for usado, o legítimo para de
 * funcionar e a pessoa é deslogada — um sinal visível de que algo aconteceu.
 * Sem rotação, o ladrão renova a sessão em silêncio por 30 dias.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TokenDeRenovacao
{
    private function __construct(
        public readonly string $hash,
        public readonly UsuarioId $usuarioId,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly \DateTimeImmutable $expiraEm,
        private ?\DateTimeImmutable $revogadoEm,
    ) {
    }

    public static function emitir(
        string $valorEmTextoPuro,
        UsuarioId $usuarioId,
        \DateTimeImmutable $agora,
        int $validadeEmDias,
    ): self {
        return new self(
            hash('sha256', $valorEmTextoPuro),
            $usuarioId,
            $agora,
            $agora->modify(sprintf('+%d days', $validadeEmDias)),
            null,
        );
    }

    public static function hashDe(string $valorEmTextoPuro): string
    {
        return hash('sha256', $valorEmTextoPuro);
    }

    public function estaValido(\DateTimeImmutable $agora): bool
    {
        return null === $this->revogadoEm && $this->expiraEm > $agora;
    }

    public function revogar(\DateTimeImmutable $agora): void
    {
        $this->revogadoEm ??= $agora;
    }

    public function revogadoEm(): ?\DateTimeImmutable
    {
        return $this->revogadoEm;
    }
}
