<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\Excecao\EventoSemLoteNaoPodeSerPublicado;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\RepositorioDeLotes;

/**
 * Fase 6.1 — RASCUNHO → PUBLICADO. A partir daqui o evento está na vitrine.
 *
 * Sobre a RN-11 ("um lote não pode ser publicado com quantidade menor que a
 * já vendida"): ela é invariante do PRÓPRIO Lote — o construtor recusa
 * vendido > total e `redimensionarPara()` recusa encolher abaixo do vendido.
 * Não existe caminho que monte um lote violado para esta publicação deixar
 * passar; o que resta a este caso de uso é a regra que só ele enxerga, a de
 * que a vitrine não recebe evento sem nada à venda.
 *
 * Publicar de novo um evento já publicado é idempotente, como o botão que o
 * dispara: a segunda chamada não tem efeito e não é erro.
 */
final readonly class PublicarEvento
{
    public function __construct(
        private RepositorioDeEventos $eventos,
        private RepositorioDeLotes $lotes,
    ) {
    }

    /** O controller já garantiu existência e posse (EventoVoter). */
    public function __invoke(Evento $evento): Evento
    {
        if ([] === $this->lotes->doEvento($evento->id)) {
            throw new EventoSemLoteNaoPodeSerPublicado(
                'Este evento não tem nenhum lote. Crie um lote antes de publicar.',
            );
        }

        $evento->publicar();
        $this->eventos->salvar($evento);

        return $evento;
    }
}
