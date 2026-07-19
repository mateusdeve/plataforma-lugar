<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Reserva\ReservaId;

/**
 * Porta de geração de identificadores e códigos.
 *
 * Está no domínio porque é o domínio que precisa criar uma Reserva nova. A
 * implementação (UUID v7, aleatoriedade criptográfica) é detalhe de
 * infraestrutura — e é o que permite ao teste injetar valores previsíveis.
 */
interface GeradorDeIdentidade
{
    public function novaReservaId(): ReservaId;

    /** RN-09: aleatório e não adivinhável. Nada de sequencial. */
    public function novoCodigoDeIngresso(): CodigoIngresso;
}
