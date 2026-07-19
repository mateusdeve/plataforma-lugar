<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

interface RepositorioDeTokens
{
    public function buscarPorHash(string $hash): ?TokenDeRenovacao;

    public function salvar(TokenDeRenovacao $token): void;

    /** Usado no logout de todas as sessões e quando a senha muda. */
    public function revogarTodosDoUsuario(UsuarioId $usuarioId, \DateTimeImmutable $agora): void;
}
