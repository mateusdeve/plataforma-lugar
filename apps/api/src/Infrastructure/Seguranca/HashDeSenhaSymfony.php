<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Domain\Usuario\HashDeSenha;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Argon2id, configurado em security.yaml.
 *
 * Argon2id e não bcrypt: bcrypt resiste a ataque por CPU, mas GPUs e ASICs o
 * quebram bem mais rápido. Argon2id é `memory-hard` — exige memória, que é
 * cara de paralelizar em hardware dedicado.
 */
final readonly class HashDeSenhaSymfony implements HashDeSenha
{
    public function __construct(private PasswordHasherFactoryInterface $fabrica)
    {
    }

    public function gerar(string $senhaEmTextoPuro): string
    {
        return $this->fabrica->getPasswordHasher(UsuarioAutenticado::class)
            ->hash($senhaEmTextoPuro);
    }

    public function confere(string $senhaEmTextoPuro, string $hash): bool
    {
        return $this->fabrica->getPasswordHasher(UsuarioAutenticado::class)
            ->verify($hash, $senhaEmTextoPuro);
    }
}
