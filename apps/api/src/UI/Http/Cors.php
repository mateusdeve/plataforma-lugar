<?php

declare(strict_types=1);

namespace Lugar\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * CORS, escrito à mão em vez de um bundle: são vinte linhas, e vinte linhas
 * legíveis valem mais que uma configuração que ninguém revisa.
 *
 * A origem permitida é EXPLÍCITA, vinda de variável de ambiente. Duas coisas
 * que NÃO são feitas aqui, ambas de propósito:
 *
 *   - `Access-Control-Allow-Origin: *` — incompatível com credenciais; o
 *     navegador recusa a combinação
 *   - refletir a origem recebida — transformaria QUALQUER site numa origem
 *     autorizada, que é o oposto de configurar CORS
 */
#[AsEventListener(event: 'kernel.request', method: 'aoReceber', priority: 250)]
#[AsEventListener(event: 'kernel.response', method: 'aoResponder')]
final readonly class Cors
{
    public function __construct(private string $origemPermitida)
    {
    }

    public function aoReceber(RequestEvent $evento): void
    {
        $requisicao = $evento->getRequest();

        // Preflight não deve chegar ao controller nem à autenticação.
        if ('OPTIONS' === $requisicao->getMethod()) {
            $evento->setResponse(new Response('', Response::HTTP_NO_CONTENT));
        }
    }

    public function aoResponder(ResponseEvent $evento): void
    {
        $origem = $evento->getRequest()->headers->get('Origin');

        if ($origem !== $this->origemPermitida) {
            return;
        }

        $cabecalhos = $evento->getResponse()->headers;
        $cabecalhos->set('Access-Control-Allow-Origin', $this->origemPermitida);
        // Sem isto o navegador não envia nem aceita o cookie de refresh.
        $cabecalhos->set('Access-Control-Allow-Credentials', 'true');
        $cabecalhos->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $cabecalhos->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Idempotency-Key');
        $cabecalhos->set('Vary', 'Origin');
    }
}
