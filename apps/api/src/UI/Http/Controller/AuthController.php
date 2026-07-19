<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Usuario\AbrirSessao;
use Lugar\Application\Usuario\CadastrarUsuario;
use Lugar\Application\Usuario\Sessao;
use Lugar\Domain\Usuario\Excecao\CredenciaisInvalidas;
use Lugar\Application\Usuario\UsuarioAtual;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\Usuario;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro, login, renovação e logout.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONDE CADA CREDENCIAL VIVE, E POR QUÊ
 *
 * O access token vai no CORPO da resposta e o front o guarda em memória. Ele
 * não vai em cookie porque é enviado no header Authorization, e um cookie
 * enviado automaticamente abriria espaço para CSRF.
 *
 * O refresh token vai em COOKIE httpOnly. JavaScript não o alcança, então um
 * XSS na página não consegue roubar a sessão de 30 dias — o pior que faz é
 * usar o access token de 15 minutos que já está na memória.
 *
 * O cookie NÃO leva atributo `Domain` (ADR-003). Sem ele o cookie é
 * host-only: nasce em api.comprarbem.store e só volta para lá. Com
 * `Domain=.comprarbem.store`, ele seria enviado a todo subdomínio — inclusive
 * a qualquer coisa hospedada no apex.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final readonly class AuthController
{
    private const string COOKIE_REFRESH = 'lugar_refresh';

    public function __construct(
        private CadastrarUsuario $cadastrar,
        private AbrirSessao $sessoes,
        private UsuarioAtual $usuarioAtual,
        private RateLimiterFactoryInterface $loginLimiter,
    ) {
    }

    #[Route('/api/auth/cadastro', name: 'auth_cadastro', methods: ['POST'])]
    public function cadastro(Request $request): JsonResponse
    {
        $dados = $this->corpo($request);

        $usuario = ($this->cadastrar)(
            email: $this->texto($dados, 'email'),
            senha: $this->texto($dados, 'senha'),
            nome: $this->texto($dados, 'nome'),
            papeis: $this->papeisDe($dados),
        );

        // Cadastro já abre sessão: pedir para a pessoa fazer login logo depois
        // de criar a conta é atrito sem propósito.
        $sessao = $this->sessoes->comCredenciais(
            $usuario->email,
            $this->texto($dados, 'senha'),
        );

        return $this->respostaComSessao($sessao, Response::HTTP_CREATED);
    }

    #[Route('/api/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $dados = $this->corpo($request);
        $email = $this->texto($dados, 'email');

        /*
          Rate limit por IP **e** por e-mail (PRD §6.3).

          Só por IP não protege: um ataque distribuído tenta a mesma conta a
          partir de milhares de endereços e nunca estoura o limite de nenhum.
          Só por e-mail também não: permite varrer contas diferentes de um IP
          só. As duas chaves juntas fecham os dois caminhos.
        */
        foreach ([$request->getClientIp() ?? 'sem-ip', mb_strtolower($email)] as $chave) {
            if (!$this->loginLimiter->create($chave)->consume()->isAccepted()) {
                return new JsonResponse(
                    [
                        'type' => 'https://comprarbem.store/erros/muitas-tentativas',
                        'title' => 'Muitas tentativas',
                        'status' => Response::HTTP_TOO_MANY_REQUESTS,
                        'detail' => 'Aguarde um minuto antes de tentar novamente.',
                    ],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    ['Content-Type' => 'application/problem+json'],
                );
            }
        }

        $sessao = $this->sessoes->comCredenciais($email, $this->texto($dados, 'senha'));

        return $this->respostaComSessao($sessao);
    }

    #[Route('/api/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $refresh = $request->cookies->get(self::COOKIE_REFRESH);

        if (!\is_string($refresh) || '' === $refresh) {
            throw new CredenciaisInvalidas();
        }

        // A renovação ROTACIONA: o token apresentado é revogado e outro nasce.
        return $this->respostaComSessao($this->sessoes->renovando($refresh));
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $refresh = $request->cookies->get(self::COOKIE_REFRESH);

        if (\is_string($refresh) && '' !== $refresh) {
            $this->sessoes->encerrar($refresh);
        }

        $resposta = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $resposta->headers->clearCookie(self::COOKIE_REFRESH, '/', null, true, true, 'lax');

        return $resposta;
    }

    #[Route('/api/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $usuario = $this->usuarioAtual->usuario();

        if (null === $usuario) {
            return new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->perfil($usuario));
    }

    // ── apoio ────────────────────────────────────────────────────────────

    private function respostaComSessao(Sessao $sessao, int $status = Response::HTTP_OK): JsonResponse
    {
        $resposta = new JsonResponse([
            'accessToken' => $sessao->accessToken,
            'usuario' => $this->perfil($sessao->usuario),
        ], $status);

        $resposta->headers->setCookie(
            Cookie::create(self::COOKIE_REFRESH)
                ->withValue($sessao->refreshEmTextoPuro)
                ->withExpires($sessao->refreshExpiraEm)
                ->withPath('/')
                ->withSecure(true)
                ->withHttpOnly(true)
                // Lax e não None: front e API são same-site sob o mesmo
                // domínio registrável, então Lax basta — e não depende de o
                // navegador aceitar cookie de terceiros (ADR-003).
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $resposta;
    }

    /**
     * @return array{id: string, nome: string, email: string, papeis: list<string>}
     */
    private function perfil(Usuario $usuario): array
    {
        return [
            'id' => $usuario->id->valor,
            'nome' => $usuario->nome(),
            'email' => $usuario->email,
            'papeis' => $usuario->papeisComoTexto(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function corpo(Request $request): array
    {
        $dados = json_decode($request->getContent(), true);

        return \is_array($dados) ? $dados : [];
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? null;

        if (!\is_string($valor) || '' === trim($valor)) {
            throw new \InvalidArgumentException(sprintf('O campo "%s" é obrigatório.', $campo));
        }

        return $valor;
    }

    /**
     * Papéis vêm do corpo, mas COMPRADOR é o único que alguém pode se
     * conceder. Aceitar ROLE_ORGANIZADOR daqui seria escalação de privilégio
     * por JSON — quem quiser organizar pede, e alguém concede.
     *
     * @param array<string, mixed> $dados
     *
     * @return list<Papel>
     */
    private function papeisDe(array $dados): array
    {
        $pedido = $dados['papel'] ?? null;

        // Organizador é autoatendimento no MVP porque não há painel de
        // administração para conceder o papel. Portaria, não: quem escala é o
        // organizador dono do evento.
        if ('organizador' === $pedido) {
            return [Papel::COMPRADOR, Papel::ORGANIZADOR];
        }

        return [Papel::COMPRADOR];
    }
}
