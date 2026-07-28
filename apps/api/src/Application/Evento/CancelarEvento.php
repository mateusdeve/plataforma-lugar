<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\RepositorioDeEventos;

/**
 * O caminho que a RN-12 aponta quando excluir é proibido: cancelar preserva
 * o rastro — quem comprou, quanto pagou, o que aconteceu.
 */
final readonly class CancelarEvento
{
    public function __construct(private RepositorioDeEventos $eventos)
    {
    }

    /** O controller já garantiu existência e posse (EventoVoter). */
    public function __invoke(Evento $evento): Evento
    {
        $evento->cancelar();
        $this->eventos->salvar($evento);

        return $evento;
    }
}
