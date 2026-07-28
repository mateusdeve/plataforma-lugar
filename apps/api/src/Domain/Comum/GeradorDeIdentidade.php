<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\IngressoId;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Pagamento\PagamentoId;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Usuario\UsuarioId;

/**
 * Porta de geração de identificadores e códigos.
 *
 * Está no domínio porque é o domínio que precisa criar uma Reserva nova. A
 * implementação (UUID v7, aleatoriedade criptográfica) é detalhe de
 * infraestrutura — e é o que permite ao teste injetar valores previsíveis.
 */
interface GeradorDeIdentidade
{
    public function novoEventoId(): EventoId;

    public function novoLoteId(): LoteId;

    public function novaReservaId(): ReservaId;

    public function novoUsuarioId(): UsuarioId;

    public function novoIngressoId(): IngressoId;

    public function novoPagamentoId(): PagamentoId;

    /** RN-09: aleatório e não adivinhável. Nada de sequencial. */
    public function novoCodigoDeIngresso(): CodigoIngresso;
}
