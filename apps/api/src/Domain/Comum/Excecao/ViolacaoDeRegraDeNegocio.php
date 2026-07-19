<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum\Excecao;

/**
 * Raiz das exceções de domínio.
 *
 * Existe para que a camada UI consiga distinguir "o usuário tentou algo que a
 * regra não permite" de "algo quebrou". A primeira vira 4xx com `type`
 * acionável (RFC 7807); a segunda vira 500 e um alerta.
 *
 * Cada subclasse carrega o `tipo()`, que é exatamente o campo `type` do
 * problem+json — é assim que o front distingue os dois 409 do sistema sem ler
 * mensagem (PRD §9).
 */
abstract class ViolacaoDeRegraDeNegocio extends \DomainException
{
    abstract public function tipo(): string;
}
