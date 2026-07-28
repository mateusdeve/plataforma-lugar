<?php

declare(strict_types=1);

namespace Lugar\Application\Evento\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * O e-mail escalado não tem conta. A escala aponta para `usuario.id`
 * (tabela `evento_operador`), então não existe "escalar alguém que ainda vai
 * se cadastrar" — a pessoa cria a conta primeiro, o organizador escala depois.
 */
final class OperadorDesconhecido extends ViolacaoDeRegraDeNegocio
{
    public function tipo(): string
    {
        return 'operador-desconhecido';
    }
}
