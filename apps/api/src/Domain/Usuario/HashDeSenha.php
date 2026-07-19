<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

/**
 * Porta de hashing.
 *
 * O domínio precisa dizer "guarde esta senha com segurança" sem conhecer
 * Argon2id, custo de memória ou a API do Symfony. A implementação vive em
 * Infrastructure/ — e trocar o algoritmo depois não toca em nada aqui.
 */
interface HashDeSenha
{
    public function gerar(string $senhaEmTextoPuro): string;

    public function confere(string $senhaEmTextoPuro, string $hash): bool;
}
