<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso;

enum StatusDoIngresso: string
{
    case EMITIDO = 'EMITIDO';
    case UTILIZADO = 'UTILIZADO';
    case CANCELADO = 'CANCELADO';
}
