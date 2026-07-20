<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

/**
 * O que o front precisa saber para levar a pessoa ao pagamento.
 *
 * `urlDePagamento` cobre tanto o checkout hospedado (redireciona) quanto o
 * modo embutido (a tela abre num iframe). Qual dos dois é decisão do
 * adaptador, e nenhum dos dois vaza para o caso de uso.
 */
final readonly class CobrancaAberta
{
    public function __construct(
        /** Id da cobrança no provedor — é por ele que o webhook reconcilia. */
        public string $referenciaDoProvedor,
        public string $urlDePagamento,
        public \DateTimeImmutable $expiraEm,
    ) {
    }
}
