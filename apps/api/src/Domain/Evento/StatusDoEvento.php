<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

enum StatusDoEvento: string
{
    case RASCUNHO = 'RASCUNHO';
    case PUBLICADO = 'PUBLICADO';
    case CANCELADO = 'CANCELADO';
}
