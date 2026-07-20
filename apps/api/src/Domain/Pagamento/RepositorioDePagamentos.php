<?php

declare(strict_types=1);

namespace Lugar\Domain\Pagamento;

use Lugar\Domain\Pagamento\Excecao\PagamentoJaRegistrado;

interface RepositorioDePagamentos
{
    public function buscarPorProvedorId(string $provedorId): ?Pagamento;

    /**
     * @throws PagamentoJaRegistrado quando o UNIQUE de `provedor_id` recusa a
     *                               escrita — dois webhooks do mesmo evento
     *                               chegaram juntos e este perdeu a corrida
     */
    public function salvar(Pagamento $pagamento): void;
}
