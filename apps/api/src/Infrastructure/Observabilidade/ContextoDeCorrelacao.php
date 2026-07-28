<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Observabilidade;

/**
 * O correlation id da unidade de trabalho ATUAL — uma requisição HTTP ou uma
 * mensagem sendo consumida pelo worker.
 *
 * É um serviço mutável de propósito, e o único deste tipo no projeto: o id
 * nasce na borda (listener HTTP ou handler da fila) e precisa estar visível
 * para o processador do Monolog em QUALQUER log emitido no meio do caminho,
 * sem que caso de uso nenhum saiba que ele existe. Passá-lo por parâmetro
 * espalharia observabilidade por todas as assinaturas da aplicação.
 */
final class ContextoDeCorrelacao
{
    private ?string $id = null;

    public function definir(string $id): void
    {
        $this->id = $id;
    }

    public function atual(): ?string
    {
        return $this->id;
    }
}
