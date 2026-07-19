<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * Uma exceção só para "e-mail não existe" E "senha errada".
 *
 * Mensagens distintas para os dois casos entregam de graça a informação de
 * quais e-mails estão cadastrados — é assim que se enumera a base de usuários
 * de um sistema. O atacante deve sair daqui sabendo exatamente o mesmo que
 * sabia antes.
 */
final class CredenciaisInvalidas extends ViolacaoDeRegraDeNegocio
{
    public function __construct()
    {
        parent::__construct('E-mail ou senha incorretos.');
    }

    public function tipo(): string
    {
        return 'credenciais-invalidas';
    }
}
