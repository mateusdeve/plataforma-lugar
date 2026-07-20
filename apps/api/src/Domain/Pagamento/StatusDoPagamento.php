<?php

declare(strict_types=1);

namespace Lugar\Domain\Pagamento;

enum StatusDoPagamento: string
{
    case APROVADO = 'APROVADO';
    case RECUSADO = 'RECUSADO';
    case ESTORNADO = 'ESTORNADO';
}
