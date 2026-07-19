<?php

declare(strict_types=1);

namespace Lugar\Application\Saude;

/**
 * Resultado de uma verificação de dependência.
 *
 * `detalhe` é texto para humano e vai para uma resposta pública — nunca deve
 * conter host, usuário, nome de banco ou mensagem de driver.
 */
final readonly class Verificacao
{
    public function __construct(
        public string $nome,
        public bool $ok,
        public string $detalhe,
    ) {
    }

    public static function ok(string $nome, string $detalhe): self
    {
        return new self($nome, true, $detalhe);
    }

    public static function falhou(string $nome, string $detalhe): self
    {
        return new self($nome, false, $detalhe);
    }
}
