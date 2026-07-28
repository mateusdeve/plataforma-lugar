<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Consulta\ConsultaDoOrganizador;
use Lugar\Application\Evento\EscalarOperador;
use Lugar\Application\Evento\RetirarOperador;
use Lugar\Application\Usuario\UsuarioAtual;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\Permissao;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * ONDE O VÍNCULO DO ADR-004 APARECE NA TELA.
 *
 * `security.yaml` já exige `ROLE_ORGANIZADOR` em tudo sob `/api/organizador`.
 * Isso é o mínimo, e sozinho não protege nada: o papel diz que a pessoa pode
 * organizar eventos, não QUAIS. Rafael e Joana têm exatamente o mesmo papel.
 *
 * O que separa um do outro é a chamada ao `EventoVoter` em `garantirAcessoA()`.
 * Removê-la deixa a aplicação funcionando, os testes de rota passando e a tela
 * idêntica — e abre o faturamento de todo organizador para qualquer outro. É
 * por isso que existe um teste dedicado a provar o 403, e não uma inspeção.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class OrganizadorController
{
    public function __construct(
        private ConsultaDoOrganizador $consulta,
        private RepositorioDeEventos $eventos,
        private UsuarioAtual $usuarioAtual,
        private AuthorizationCheckerInterface $autorizacao,
        private EscalarOperador $escalar,
        private RetirarOperador $retirar,
    ) {
    }

    /**
     * Os eventos de quem está pedindo — nunca um id vindo da requisição.
     *
     * Aceitar `?organizadorId=` aqui seria oferecer a base inteira a quem
     * trocasse o parâmetro. O único id em que se pode confiar é o do token.
     */
    #[Route('/api/organizador/eventos', name: 'organizador_eventos', methods: ['GET'])]
    public function eventos(): JsonResponse
    {
        $usuario = $this->usuarioAtual->usuario();

        if (null === $usuario) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $this->semCache(new JsonResponse([
            'itens' => $this->consulta->eventosDe($usuario->id->valor),
        ]));
    }

    #[Route('/api/organizador/eventos/{id}/painel', name: 'organizador_painel', methods: ['GET'])]
    public function painel(string $id): JsonResponse
    {
        $this->garantirAcessoA($id, Permissao::VER_PAINEL);

        $painel = $this->consulta->painel($id);

        if (null === $painel) {
            throw new NotFoundHttpException();
        }

        return $this->semCache(new JsonResponse($painel));
    }

    // ── escala da portaria (fase 6.4) ────────────────────────────────────

    #[Route('/api/organizador/eventos/{id}/operadores', name: 'organizador_operadores', methods: ['GET'])]
    public function operadores(string $id): JsonResponse
    {
        $this->garantirAcessoA($id, Permissao::ESCALAR_PORTARIA);

        return $this->semCache(new JsonResponse([
            'itens' => $this->consulta->operadores($id),
        ]));
    }

    #[Route('/api/organizador/eventos/{id}/operadores', name: 'organizador_escalar', methods: ['POST'])]
    public function escalarOperador(string $id, Request $request): JsonResponse
    {
        $evento = $this->garantirAcessoA($id, Permissao::ESCALAR_PORTARIA);

        $dados = json_decode($request->getContent(), true);
        $email = \is_array($dados) ? ($dados['email'] ?? null) : null;

        if (!\is_string($email) || '' === trim($email)) {
            throw new \InvalidArgumentException('O campo "email" é obrigatório.');
        }

        $operador = ($this->escalar)($evento->id, trim($email));

        return new JsonResponse([
            'id' => $operador->id->valor,
            'nome' => $operador->nome(),
            'email' => $operador->email,
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/organizador/eventos/{id}/operadores/{usuarioId}', name: 'organizador_retirar_operador', methods: ['DELETE'])]
    public function retirarOperador(string $id, string $usuarioId): JsonResponse
    {
        $evento = $this->garantirAcessoA($id, Permissao::ESCALAR_PORTARIA);

        ($this->retirar)($evento->id, $usuarioId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── apoio ────────────────────────────────────────────────────────────

    private function garantirAcessoA(string $eventoId, string $permissao): Evento
    {
        $evento = $this->eventos->buscar(new EventoId($eventoId));

        if (null === $evento) {
            throw new NotFoundHttpException();
        }

        /*
          403 e não 404 para evento alheio, de propósito.

          Devolver 404 esconderia a existência do evento — mas os ids já são
          públicos na vitrine, então não haveria segredo a proteger, só um erro
          enganoso: quem chamasse a rota certa com credencial válida veria
          "não existe" sobre um evento que existe. O status precisa dizer a
          verdade: existe, e você não tem acesso.
        */
        if (!$this->autorizacao->isGranted($permissao, $evento)) {
            throw new AccessDeniedHttpException();
        }

        return $evento;
    }

    /**
     * O painel mostra reservas expirando e estoque disputado. Um valor de
     * cinco segundos atrás é uma decisão tomada sobre o passado — e, sendo
     * dados de uma conta, não pode encostar em cache compartilhado.
     */
    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }
}
