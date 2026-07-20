<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Consulta\ConsultaDaPortaria;
use Lugar\Application\Portaria\ValidarIngresso;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\Permissao;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A PORTA — PLAN fase 7.
 *
 * DE QUAL EVENTO É ESTA PORTA VEM NA REQUISIÇÃO, E ISSO NÃO É FALHA
 *
 * O operador informa em que porta está. Parece frágil — e seria, se fosse a
 * única coisa dita. Mas o `PortariaVoter` confere se esta pessoa está
 * ESCALADA naquele evento (`evento_operador`). Mentir sobre a porta só
 * funciona para uma porta onde ela já poderia validar.
 *
 * A alternativa — deduzir o evento a partir do ingresso lido — seria pior: a
 * autorização passaria a depender do que o CÓDIGO diz, e um código de outro
 * evento autorizaria a si mesmo. É justamente a recusa da RN 7.3 que
 * desapareceria.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class PortariaController
{
    public function __construct(
        private ValidarIngresso $validar,
        private ConsultaDaPortaria $consulta,
        private RepositorioDeEventos $eventos,
        private AuthorizationCheckerInterface $autorizacao,
    ) {
    }

    /**
     * O estado da porta: qual evento é, e quantos já entraram.
     *
     * Serve a duas coisas ao mesmo tempo. Alimenta o contador da tela — que
     * precisa do total real, não do que esta catraca contou — e responde 403
     * ANTES da primeira leitura, para quem abriu a porta errada descobrir
     * agora e não com a fila na frente.
     */
    #[Route('/api/portaria/{eventoId}', name: 'portaria_estado', methods: ['GET'])]
    public function estado(string $eventoId): JsonResponse
    {
        $evento = $this->portaAutorizada($eventoId);

        return $this->semCache(new JsonResponse([
            'eventoId' => $evento->id->valor,
            'eventoTitulo' => $evento->titulo(),
            'entradas' => $this->consulta->entradasDoEvento($evento->id->valor),
        ]));
    }

    /**
     * Consulta sem consumir — para conferir um ingresso antes de liberar, ou
     * para explicar a alguém por que a entrada foi recusada.
     */
    #[Route('/api/ingressos/{codigo}', name: 'portaria_consultar', methods: ['GET'])]
    public function consultar(string $codigo, Request $request): JsonResponse
    {
        $evento = $this->portaAutorizada($request->query->get('eventoId'));

        $ingresso = $this->consulta->porCodigo($codigo);

        if (null === $ingresso || $ingresso['eventoId'] !== $evento->id->valor) {
            // Um ingresso de outro evento não é assunto desta porta: devolver
            // os dados dele vazaria nome e e-mail de comprador de um evento
            // que este operador não opera.
            throw new NotFoundHttpException();
        }

        return $this->semCache(new JsonResponse($ingresso));
    }

    #[Route('/api/ingressos/{codigo}/utilizar', name: 'portaria_utilizar', methods: ['POST'])]
    public function utilizar(string $codigo, Request $request): JsonResponse
    {
        $dados = json_decode($request->getContent(), true);
        $corpo = \is_array($dados) ? $dados : [];

        $evento = $this->portaAutorizada($corpo['eventoId'] ?? null);

        // Estoura com IngressoJaUtilizado (RN-10, com horário),
        // IngressoDeOutroEvento (7.3) ou CodigoDesconhecido — cada um vira um
        // `type` distinto no problem+json, e é por ele que a tela escolhe a
        // mensagem, nunca pela frase.
        ($this->validar)($codigo, $evento->id->valor);

        $ingresso = $this->consulta->porCodigo($codigo);

        return $this->semCache(new JsonResponse([
            'ingresso' => $ingresso,
            'entradas' => $this->consulta->entradasDoEvento($evento->id->valor),
        ], Response::HTTP_OK));
    }

    // ── apoio ────────────────────────────────────────────────────────────

    /**
     * O vínculo do ADR-004 aplicado à porta: papel não basta, é preciso estar
     * escalado NESTE evento. Quem decide é o `PortariaVoter` — que também
     * libera o organizador dono, sem precisar se escalar.
     */
    private function portaAutorizada(mixed $eventoId): Evento
    {
        if (!\is_string($eventoId) || '' === trim($eventoId)) {
            throw new NotFoundHttpException();
        }

        $evento = $this->eventos->buscar(new EventoId($eventoId))
            ?? throw new NotFoundHttpException();

        if (!$this->autorizacao->isGranted(Permissao::VALIDAR_INGRESSO, $evento)) {
            throw new AccessDeniedHttpException();
        }

        return $evento;
    }

    /**
     * Nunca em cache. É a tela que decide se alguém entra, e uma resposta de
     * cinco segundos atrás pode dizer "pode entrar" sobre um ingresso que
     * acabou de ser usado na catraca ao lado.
     */
    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }
}
