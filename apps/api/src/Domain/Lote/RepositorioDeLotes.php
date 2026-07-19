<?php

declare(strict_types=1);

namespace Lugar\Domain\Lote;

interface RepositorioDeLotes
{
    public function buscar(LoteId $id): ?Lote;

    /**
     * Busca o lote adquirindo LOCK PESSIMISTA na linha (SELECT ... FOR UPDATE).
     *
     * Este é o método que decide a corrida pelo último ingresso. Quem chegar
     * primeiro trava a linha; os demais ficam BLOQUEADOS no banco até a
     * transação do primeiro terminar — e então releem o estoque já atualizado.
     *
     * Só faz sentido dentro de uma transação. Fora dela, o lock é liberado
     * imediatamente e a garantia evapora.
     */
    public function buscarParaAtualizacao(LoteId $id): ?Lote;

    public function salvar(Lote $lote): void;
}
