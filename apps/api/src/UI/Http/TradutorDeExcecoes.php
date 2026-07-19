<?php

declare(strict_types=1);

namespace Lugar\UI\Http;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Domain\Lote\Excecao\ForaDaJanelaDeVenda;
use Lugar\Domain\Lote\Excecao\QuantidadeInvalida;
use Lugar\Domain\Usuario\Excecao\CredenciaisInvalidas;
use Lugar\Domain\Usuario\Excecao\EmailJaCadastrado;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * TODA EXCEÇÃO DE DOMÍNIO VIRA UM problem+json COM `type` ACIONÁVEL.
 *
 * O PRD §9 exige RFC 7807, e o motivo está no front: o sistema tem DOIS erros
 * 409 com significados e tratamentos completamente diferentes.
 *
 *   estoque-insuficiente → banner na tela do evento, a pessoa continua ali
 *   reserva-expirada     → tela própria, a pessoa recomeça
 *
 * Mesmo status HTTP. Se o front tivesse que decidir pela MENSAGEM, qualquer
 * ajuste de texto — inclusive uma tradução — quebraria o comportamento. O
 * campo `type` é um identificador estável; a mensagem é para humanos.
 *
 * Cada exceção de domínio carrega seu `tipo()`, e este tradutor só o promove
 * a URI e escolhe o status. A regra de negócio nunca precisa saber o que é
 * HTTP.
 * ═══════════════════════════════════════════════════════════════════════════
 */
/*
 * Prioridade -64, e não 0, por um motivo aprendido na marra: em 0 este
 * listener roda ANTES do `ErrorListener::logKernelException` do Symfony e
 * interrompe a propagação — o erro vira um 500 bonito e NUNCA é registrado.
 * Um tradutor de exceções que apaga o log é pior que nenhum.
 *
 * Em -64 o log acontece primeiro, e ainda ficamos à frente do
 * `ErrorListener::onKernelException` (-128), que montaria a resposta padrão.
 */
#[AsEventListener(event: 'kernel.exception', priority: -64)]
final readonly class TradutorDeExcecoes
{
    private const string BASE = 'https://comprarbem.store/erros/';

    public function __invoke(ExceptionEvent $evento): void
    {
        $erro = $evento->getThrowable();

        if (!$this->ehRotaDaApi($evento)) {
            return;
        }

        if ($erro instanceof ViolacaoDeRegraDeNegocio) {
            $evento->setResponse($this->problema(
                tipo: $erro->tipo(),
                titulo: $this->tituloDe($erro),
                status: $this->statusDe($erro),
                detalhe: $erro->getMessage(),
            ));

            return;
        }

        if ($erro instanceof \InvalidArgumentException) {
            $evento->setResponse($this->problema(
                tipo: 'entrada-invalida',
                titulo: 'Dados inválidos',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detalhe: $erro->getMessage(),
            ));

            return;
        }

        if ($erro instanceof HttpExceptionInterface) {
            $evento->setResponse($this->problema(
                tipo: 'erro-http',
                titulo: Response::$statusTexts[$erro->getStatusCode()] ?? 'Erro',
                status: $erro->getStatusCode(),
                // A mensagem de exceções HTTP do framework pode descrever
                // rota, classe e configuração. Nada disso vai para a resposta.
                detalhe: null,
            ));

            return;
        }

        // Qualquer outra coisa é bug. O detalhe vai para o log, nunca para o
        // corpo — mensagem de driver e stack trace descrevem a infraestrutura.
        $evento->setResponse($this->problema(
            tipo: 'erro-interno',
            titulo: 'Erro interno',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
            detalhe: null,
        ));
    }

    private function statusDe(ViolacaoDeRegraDeNegocio $erro): int
    {
        return match (true) {
            $erro instanceof CredenciaisInvalidas => Response::HTTP_UNAUTHORIZED,
            $erro instanceof EmailJaCadastrado => Response::HTTP_CONFLICT,
            $erro instanceof EstoqueInsuficiente => Response::HTTP_CONFLICT,
            $erro instanceof QuantidadeInvalida,
            $erro instanceof ForaDaJanelaDeVenda => Response::HTTP_UNPROCESSABLE_ENTITY,
            // Os demais conflitos de regra: reserva expirada, limite de
            // reservas ativas, ingresso já utilizado.
            default => Response::HTTP_CONFLICT,
        };
    }

    private function tituloDe(ViolacaoDeRegraDeNegocio $erro): string
    {
        return match ($erro->tipo()) {
            'estoque-insuficiente' => 'Esgotou enquanto você decidia',
            'reserva-expirada' => 'O tempo acabou',
            'limite-reservas-ativas' => 'Você já tem reservas em aberto',
            'ingresso-ja-utilizado' => 'Ingresso já utilizado',
            'credenciais-invalidas' => 'Não foi possível entrar',
            'email-ja-cadastrado' => 'E-mail já cadastrado',
            default => 'Não foi possível concluir',
        };
    }

    private function problema(string $tipo, string $titulo, int $status, ?string $detalhe): JsonResponse
    {
        $corpo = [
            'type' => self::BASE.$tipo,
            'title' => $titulo,
            'status' => $status,
        ];

        if (null !== $detalhe) {
            $corpo['detail'] = $detalhe;
        }

        return new JsonResponse($corpo, $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    private function ehRotaDaApi(ExceptionEvent $evento): bool
    {
        $caminho = $evento->getRequest()->getPathInfo();

        return str_starts_with($caminho, '/api') || str_starts_with($caminho, '/health');
    }
}
