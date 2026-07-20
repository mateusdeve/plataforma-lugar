<?php

declare(strict_types=1);

namespace Lugar\Application\Notificacao;

use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Reserva\Reserva;

/**
 * Porta de notificação.
 *
 * Existe para que `ConfirmarPagamento` possa avisar o comprador sem importar
 * `Symfony\Component\Mailer` nem `MessageBusInterface` — o Deptrac proíbe
 * `Application` de conhecer o framework, e com razão: enviar e-mail é decisão
 * de negócio ("avise quem comprou"), enquanto SMTP, fila e retentativa são
 * decisões de infraestrutura.
 */
interface Notificador
{
    /**
     * @param list<Ingresso> $ingressos
     */
    public function confirmacaoDeCompra(Reserva $reserva, array $ingressos): void;
}
