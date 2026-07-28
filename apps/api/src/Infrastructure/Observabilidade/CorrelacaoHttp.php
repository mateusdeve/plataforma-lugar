<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Observabilidade;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Fase 8.1 — o correlation id atravessando front e API.
 *
 * O front gera um id por requisição e o manda em `X-Correlation-Id`
 * (lib/api.ts). Aqui ele é aceito e devolvido na resposta: quando alguém
 * reportar "deu erro ao pagar", o id que aparece no console do navegador é o
 * mesmo que está nos logs da API — e, via mensagem da fila, nos do worker.
 *
 * Prioridade 300: antes do Cors (250), que responde o preflight sem passar
 * pelo resto — e antes de qualquer coisa que possa logar.
 */
#[AsEventListener(event: 'kernel.request', method: 'aoReceber', priority: 300)]
#[AsEventListener(event: 'kernel.response', method: 'aoResponder')]
final readonly class CorrelacaoHttp
{
    public const string CABECALHO = 'X-Correlation-Id';

    public function __construct(private ContextoDeCorrelacao $contexto)
    {
    }

    public function aoReceber(RequestEvent $evento): void
    {
        $recebido = $evento->getRequest()->headers->get(self::CABECALHO);

        // O formato é validado porque o valor vem de fora e vai parar em log:
        // aceitar qualquer coisa abriria os logs para injeção de conteúdo
        // arbitrário — quebra de linha forjando entradas, por exemplo.
        $valido = \is_string($recebido) && 1 === preg_match('/^[A-Za-z0-9-]{8,64}$/', $recebido);

        $this->contexto->definir($valido ? $recebido : (string) Uuid::v7());
    }

    public function aoResponder(ResponseEvent $evento): void
    {
        $id = $this->contexto->atual();

        if (null !== $id) {
            $evento->getResponse()->headers->set(self::CABECALHO, $id);
        }
    }
}
