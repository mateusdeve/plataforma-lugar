<?php

declare(strict_types=1);

namespace Lugar\Application\Usuario;

use Lugar\Domain\Usuario\Usuario;

/**
 * Porta: quem está autenticado nesta requisição.
 *
 * Existe porque a camada UI precisa do usuário atual mas NÃO pode conhecer o
 * adaptador `UsuarioAutenticado`, que é infraestrutura. O Deptrac pegou
 * exatamente isso — o controller importando a classe de Infrastructure.
 *
 * Com esta porta, o controller pede um `Usuario` de domínio e não sabe que
 * existe um componente de segurança do Symfony por trás.
 */
interface UsuarioAtual
{
    public function usuario(): ?Usuario;
}
