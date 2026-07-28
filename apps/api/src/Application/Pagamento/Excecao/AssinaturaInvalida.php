<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento\Excecao;

/**
 * A requisição não veio do provedor — ou veio adulterada.
 *
 * A mensagem é deliberadamente pobre. Dizer "timestamp fora da janela" ou
 * "assinatura v1 não confere" ensina quem está tentando forjar exatamente o
 * que corrigir na próxima tentativa. O detalhe vai para o log; a resposta leva
 * 400 e nada mais.
 */
final class AssinaturaInvalida extends \RuntimeException
{
    public function __construct(string $motivoParaOLog = 'assinatura inválida')
    {
        parent::__construct($motivoParaOLog);
    }
}
