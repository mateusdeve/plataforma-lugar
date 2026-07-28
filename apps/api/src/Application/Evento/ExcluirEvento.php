<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Application\Comum\Transacao;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\Excecao\EventoComVendasNaoPodeSerExcluido;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Reserva\RepositorioDeReservas;

/**
 * Fase 6.1 — RN-12: evento com qualquer venda confirmada não pode ser
 * excluído, apenas cancelado.
 *
 * A contagem e a exclusão vivem na MESMA transação: entre um SELECT solto e
 * o DELETE, um webhook de pagamento poderia confirmar a primeira venda — e o
 * evento sumiria com um ingresso emitido apontando para o nada.
 */
final readonly class ExcluirEvento
{
    public function __construct(
        private Transacao $transacao,
        private RepositorioDeEventos $eventos,
        private RepositorioDeReservas $reservas,
    ) {
    }

    /**
     * O controller já garantiu existência e posse (EventoVoter).
     *
     * @throws EventoComVendasNaoPodeSerExcluido 409 type=evento-com-vendas
     */
    public function __invoke(Evento $evento): void
    {
        $this->transacao->executar(function () use ($evento): void {
            $evento->garantirQuePodeSerExcluido(
                $this->reservas->contarConfirmadasNoEvento($evento->id),
            );

            $this->eventos->excluir($evento);
        });
    }
}
