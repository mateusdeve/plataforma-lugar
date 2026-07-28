<?php

declare(strict_types=1);

namespace Lugar\UI\Http;

use Lugar\Application\Evento\Excecao\OperadorDesconhecido;
use Lugar\Application\Pagamento\Excecao\ValorNaoConfere;
use Lugar\Application\Portaria\CodigoDesconhecido;
use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;
use Lugar\Domain\Ingresso\Excecao\IngressoJaUtilizado;
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
                extras: $this->extrasDe($erro),
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
            // Na porta, "não existe" e "existe e já entrou" pedem telas
            // diferentes. O status carrega essa diferença junto com o `type`.
            $erro instanceof CodigoDesconhecido => Response::HTTP_NOT_FOUND,
            // Mesmo raciocínio da porta: e-mail sem conta é "não existe",
            // não um conflito de estado.
            $erro instanceof OperadorDesconhecido => Response::HTTP_NOT_FOUND,
            $erro instanceof EmailJaCadastrado => Response::HTTP_CONFLICT,
            $erro instanceof EstoqueInsuficiente => Response::HTTP_CONFLICT,
            $erro instanceof QuantidadeInvalida,
            $erro instanceof ForaDaJanelaDeVenda,
            // Não é conflito de estado: o webhook chegou bem formado e
            // assinado, e o VALOR é que não fecha. 422 diz isso; 409 sugeriria
            // que tentar de novo mais tarde resolveria, e não resolve.
            $erro instanceof ValorNaoConfere => Response::HTTP_UNPROCESSABLE_ENTITY,
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
            'ingresso-de-outro-evento' => 'Ingresso de outro evento',
            'codigo-desconhecido' => 'Código não encontrado',
            'valor-nao-confere' => 'O valor pago não confere',
            'credenciais-invalidas' => 'Não foi possível entrar',
            'email-ja-cadastrado' => 'E-mail já cadastrado',
            'evento-com-vendas' => 'Este evento já tem vendas',
            'evento-sem-lote' => 'Evento sem lote não vai à vitrine',
            'evento-cancelado' => 'Evento cancelado não volta',
            'operador-desconhecido' => 'Conta não encontrada',
            default => 'Não foi possível concluir',
        };
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * DADO QUE A TELA PRECISA VAI EM CAMPO PRÓPRIO, NUNCA NA MENSAGEM.
     *
     * A recusa da RN-10 mostra o horário da primeira entrada. Ele está no
     * `detail` ("Ingresso já utilizado às 19h42."), e seria tentador o front
     * extrair de lá com uma expressão regular.
     *
     * Isso quebra na primeira vez que alguém melhorar a frase — ou traduzir a
     * interface. O `detail` é para humanos lerem; a extensão abaixo é para a
     * tela consumir. A RFC 7807 prevê membros próprios exatamente para isto.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @return array<string, mixed>
     */
    private function extrasDe(ViolacaoDeRegraDeNegocio $erro): array
    {
        if ($erro instanceof IngressoJaUtilizado) {
            return ['utilizadoEm' => $erro->utilizadoEm->format(\DATE_ATOM)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function problema(
        string $tipo,
        string $titulo,
        int $status,
        ?string $detalhe,
        array $extras = [],
    ): JsonResponse {
        $corpo = [
            'type' => self::BASE.$tipo,
            'title' => $titulo,
            'status' => $status,
        ];

        if (null !== $detalhe) {
            $corpo['detail'] = $detalhe;
        }

        $corpo += $extras;

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
