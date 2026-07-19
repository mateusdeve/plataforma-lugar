<?php

declare(strict_types=1);

namespace Lugar\Application\Usuario;

use Lugar\Domain\Usuario\Usuario;

/**
 * O par de credenciais devolvido no login e na renovação.
 *
 * O `refreshEmTextoPuro` existe apenas neste objeto e no cookie que o
 * controller escreve. Do lado do servidor, só o hash sobrevive.
 */
final readonly class Sessao
{
    public function __construct(
        public Usuario $usuario,
        public string $accessToken,
        public string $refreshEmTextoPuro,
        public \DateTimeImmutable $refreshExpiraEm,
    ) {
    }
}
