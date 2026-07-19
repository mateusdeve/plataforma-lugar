<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Lugar\Domain\Usuario\UsuarioId;

/** @extends TipoDeIdentidade<UsuarioId> */
final class UsuarioIdType extends TipoDeIdentidade
{
    public const string NOME = 'usuario_id';

    public function getName(): string
    {
        return self::NOME;
    }

    protected function classe(): string
    {
        return UsuarioId::class;
    }
}
