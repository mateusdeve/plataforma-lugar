<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Usuario\UsuarioId;

/**
 * Tira a pessoa da escala DESTE evento. O papel ROLE_PORTARIA fica: ela pode
 * estar escalada em outras portas, e o papel sem escala não abre nenhuma
 * (PortariaVoter exige os dois).
 *
 * Retirar quem não está escalado é um DELETE que não encontra linha — o
 * desfecho pedido já é o real, e repetir o clique não vira erro.
 */
final readonly class RetirarOperador
{
    public function __construct(private EscalaDePortaria $escala)
    {
    }

    /** O controller já garantiu posse do evento (EventoVoter). */
    public function __invoke(EventoId $eventoId, string $usuarioId): void
    {
        $this->escala->retirarDaEscala(new UsuarioId($usuarioId), $eventoId);
    }
}
