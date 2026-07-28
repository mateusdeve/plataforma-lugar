<?php

declare(strict_types=1);

namespace Lugar\Domain\Pagamento\Excecao;

/**
 * O `provedor_id` já existe: este webhook é reentrega de um evento processado.
 *
 * NÃO estende ViolacaoDeRegraDeNegocio de propósito. Não é o usuário tentando
 * algo proibido — é o gateway cumprindo a própria política de entrega. O
 * webhook responde 200, porque para o provedor está tudo certo: a mensagem foi
 * recebida e o efeito já aconteceu. Responder erro faria ele reenviar de novo,
 * para sempre.
 */
final class PagamentoJaRegistrado extends \RuntimeException
{
    public function __construct(public readonly string $provedorId)
    {
        parent::__construct(sprintf('O pagamento %s já havia sido registrado.', $provedorId));
    }
}
