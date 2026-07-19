<?php

declare(strict_types=1);

namespace Lugar\Application\Saude;

/**
 * Porta. Cada dependência externa que precisa estar de pé implementa esta
 * interface em Infrastructure/ — hoje o banco e a fila.
 *
 * A interface vive aqui, junto de quem a usa, e não junto de quem a
 * implementa. É a inversão de dependência que permite ao caso de uso não
 * conhecer Doctrine.
 */
interface VerificadorDeDependencia
{
    public function verificar(): Verificacao;
}
