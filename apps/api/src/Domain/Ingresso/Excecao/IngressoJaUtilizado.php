<?php

declare(strict_types=1);

namespace Lugar\Domain\Ingresso\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * RN-10. A recusa carrega o horário da primeira entrada — na porta, "não
 * entra" sem explicação gera discussão com a fila parada atrás.
 */
final class IngressoJaUtilizado extends ViolacaoDeRegraDeNegocio
{
    public function __construct(public readonly \DateTimeImmutable $utilizadoEm)
    {
        parent::__construct(
            sprintf('Ingresso já utilizado às %s.', $utilizadoEm->format('H\hi')),
        );
    }

    public function tipo(): string
    {
        return 'ingresso-ja-utilizado';
    }
}
