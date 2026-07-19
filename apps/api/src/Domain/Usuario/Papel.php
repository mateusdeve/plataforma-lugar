<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

/**
 * Papéis ACUMULÁVEIS (ADR-004): um usuário pode ser organizador e portaria ao
 * mesmo tempo. Um organizador conferindo a entrada do próprio evento é o caso
 * comum, não a exceção.
 *
 * O prefixo ROLE_ é convenção do Symfony, mas o valor é só uma string — o
 * domínio não sabe que existe um framework que a interpreta.
 */
enum Papel: string
{
    case COMPRADOR = 'ROLE_COMPRADOR';
    case ORGANIZADOR = 'ROLE_ORGANIZADOR';
    case PORTARIA = 'ROLE_PORTARIA';
}
