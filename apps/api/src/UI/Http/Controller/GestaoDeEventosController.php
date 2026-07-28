<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Evento\CancelarEvento;
use Lugar\Application\Evento\CriarEvento;
use Lugar\Application\Evento\CriarEventoComando;
use Lugar\Application\Evento\ExcluirEvento;
use Lugar\Application\Evento\PublicarEvento;
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
 * Fase 6.1 — o ciclo de vida do evento: criar, publicar, cancelar, excluir.
 *
 * O `security.yaml` exige ROLE_ORGANIZADOR em tudo que não é GET sob
 * `/api/eventos`. Como sempre, o papel é o mínimo: quem decide POSSE é o
 * `EventoVoter`, chamado em `garantirAcessoA()` — publicar o evento de outro
 * organizador responde 403 com token válido, e há teste provando.
 */
final readonly class GestaoDeEventosController
{
    public function __construct(
        private CriarEvento $criar,
        private PublicarEvento $publicar,
        private CancelarEvento $cancelar,
        private ExcluirEvento $excluir,
        private RepositorioDeEventos $eventos,
        private UsuarioAtual $usuarioAtual,
        private AuthorizationCheckerInterface $autorizacao,
    ) {
    }

    #[Route('/api/eventos', name: 'eventos_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        $usuario = $this->usuarioAtual->usuario();

        if (null === $usuario) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $dados = $this->corpo($request);

        // O organizador é SEMPRE quem está pedindo — nunca um campo do corpo.
        // Aceitar `organizadorId` na requisição deixaria qualquer organizador
        // criar eventos em nome de outro.
        $evento = ($this->criar)(new CriarEventoComando(
            organizadorId: $usuario->id->valor,
            titulo: $this->texto($dados, 'titulo'),
            local: $this->texto($dados, 'local'),
            cidade: $this->texto($dados, 'cidade'),
            iniciaEm: $this->instante($dados, 'iniciaEm'),
            descricao: $this->textoOpcional($dados, 'descricao'),
            prazoReservaMinutos: $this->inteiroOpcional($dados, 'prazoReservaMinutos', 10),
            lotes: $this->lotes($dados),
        ));

        return new JsonResponse($this->comoJson($evento), Response::HTTP_CREATED);
    }

    #[Route('/api/eventos/{id}/publicar', name: 'eventos_publicar', methods: ['POST'])]
    public function publicar(string $id): JsonResponse
    {
        $evento = $this->garantirAcessoA($id, Permissao::PUBLICAR);

        return new JsonResponse($this->comoJson(($this->publicar)($evento)));
    }

    #[Route('/api/eventos/{id}/cancelar', name: 'eventos_cancelar', methods: ['POST'])]
    public function cancelar(string $id): JsonResponse
    {
        $evento = $this->garantirAcessoA($id, Permissao::EDITAR);

        return new JsonResponse($this->comoJson(($this->cancelar)($evento)));
    }

    /** RN-12: com venda confirmada responde 409 type=evento-com-vendas. */
    #[Route('/api/eventos/{id}', name: 'eventos_excluir', methods: ['DELETE'])]
    public function excluir(string $id): JsonResponse
    {
        $evento = $this->garantirAcessoA($id, Permissao::EDITAR);

        ($this->excluir)($evento);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // ── apoio ────────────────────────────────────────────────────────────

    private function garantirAcessoA(string $eventoId, string $permissao): Evento
    {
        $evento = $this->eventos->buscar(new EventoId($eventoId));

        if (null === $evento) {
            throw new NotFoundHttpException();
        }

        // 403 e não 404 para evento alheio — o raciocínio completo está no
        // OrganizadorController, que tomou a decisão primeiro.
        if (!$this->autorizacao->isGranted($permissao, $evento)) {
            throw new AccessDeniedHttpException();
        }

        return $evento;
    }

    /**
     * @return array<string, mixed>
     */
    private function comoJson(Evento $evento): array
    {
        return [
            'id' => $evento->id->valor,
            'titulo' => $evento->titulo(),
            'status' => $evento->status()->value,
        ];
    }

    // ── leitura tipada do corpo ──────────────────────────────────────────

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

        return trim($valor);
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function textoOpcional(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? '';

        return \is_string($valor) ? trim($valor) : '';
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function inteiroOpcional(array $dados, string $campo, int $padrao): int
    {
        $valor = $dados[$campo] ?? $padrao;

        if (!\is_int($valor)) {
            throw new \InvalidArgumentException(sprintf('O campo "%s" deve ser um número.', $campo));
        }

        return $valor;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function instante(array $dados, string $campo): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->texto($dados, $campo));
        } catch (\DateMalformedStringException) {
            throw new \InvalidArgumentException(
                sprintf('O campo "%s" deve ser uma data válida (ISO 8601).', $campo),
            );
        }
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<array{nome: string, precoCentavos: int, quantidade: int}>
     */
    private function lotes(array $dados): array
    {
        $lotes = $dados['lotes'] ?? null;

        if (!\is_array($lotes) || [] === $lotes) {
            throw new \InvalidArgumentException('O evento precisa de ao menos um lote.');
        }

        return array_values(array_map(function (mixed $lote): array {
            if (!\is_array($lote)) {
                throw new \InvalidArgumentException('Cada lote deve ser um objeto.');
            }

            /** @var array<string, mixed> $lote */
            $preco = $lote['precoCentavos'] ?? null;
            $quantidade = $lote['quantidade'] ?? null;

            if (!\is_int($preco) || !\is_int($quantidade)) {
                throw new \InvalidArgumentException(
                    'Cada lote precisa de "precoCentavos" e "quantidade" inteiros.',
                );
            }

            return [
                'nome' => $this->texto($lote, 'nome'),
                'precoCentavos' => $preco,
                'quantidade' => $quantidade,
            ];
        }, $lotes));
    }
}
