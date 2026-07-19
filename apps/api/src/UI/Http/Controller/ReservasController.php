<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Reserva\CancelarReserva;
use Lugar\Application\Reserva\CriarReserva;
use Lugar\Application\Reserva\CriarReservaComando;
use Lugar\Application\Usuario\UsuarioAtual;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Comum\Relogio;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O caminho crítico do produto.
 *
 * O POST daqui é a porta de entrada do lock pessimista. Tudo que decide a
 * corrida pelo último ingresso está em CriarReserva — este controller só
 * traduz HTTP para comando e comando para HTTP.
 */
final readonly class ReservasController
{
    public function __construct(
        private CriarReserva $criar,
        private CancelarReserva $cancelar,
        private RepositorioDeReservas $reservas,
        private UsuarioAtual $usuarioAtual,
        private Relogio $relogio,
        private RateLimiterFactoryInterface $reservasLimiter,
    ) {
    }

    #[Route('/api/reservas', name: 'reservas_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        // PRD §6.3: 10 reservas por minuto por IP.
        if (!$this->reservasLimiter->create($request->getClientIp() ?? 'sem-ip')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $dados = $this->corpo($request);

        /*
          Idempotency-Key (PRD §6.2).

          Rede móvel derruba a requisição no meio e a pessoa aperta o botão de
          novo. Sem esta chave, ela reserva dois lugares e paga por um. O front
          gera um UUID no clique e o repete em qualquer retentativa daquele
          mesmo clique.
        */
        $chave = $request->headers->get('Idempotency-Key');

        // Comprador logado usa o e-mail da conta; convidado informa o dele
        // (ADR-004 manteve o checkout de convidado de propósito).
        $usuario = $this->usuarioAtual->usuario();
        $email = null !== $usuario ? $usuario->email : $this->texto($dados, 'compradorEmail');

        $reserva = ($this->criar)(new CriarReservaComando(
            loteId: $this->texto($dados, 'loteId'),
            compradorEmail: $email,
            quantidade: $this->inteiro($dados, 'quantidade'),
            chaveDeIdempotencia: \is_string($chave) && '' !== $chave ? $chave : null,
        ));

        return new JsonResponse($this->comoJson($reserva), Response::HTTP_CREATED);
    }

    #[Route('/api/reservas/{id}', name: 'reservas_consultar', methods: ['GET'])]
    public function consultar(string $id): JsonResponse
    {
        $reserva = $this->reservas->buscar(new ReservaId($id));

        if (null === $reserva) {
            throw new NotFoundHttpException();
        }

        $resposta = new JsonResponse($this->comoJson($reserva));

        // NUNCA em cache: é daqui que o contador da tela deriva o tempo, e um
        // valor de cinco segundos atrás é cinco segundos a menos do prazo.
        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }

    #[Route('/api/reservas/{id}', name: 'reservas_cancelar', methods: ['DELETE'])]
    public function cancelar(string $id): JsonResponse
    {
        ($this->cancelar)($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function comoJson(Reserva $reserva): array
    {
        $agora = $this->relogio->agora();

        return [
            'id' => $reserva->id->valor,
            'loteId' => $reserva->loteId->valor,
            'quantidade' => $reserva->quantidade,
            'status' => $reserva->status()->value,
            'expiraEm' => $reserva->expiraEm->format(\DATE_ATOM),
            // O par que o contador do front usa: o instante absoluto E os
            // segundos restantes, para corrigir defasagem de relógio entre
            // servidor e navegador.
            'segundosRestantes' => $reserva->segundosRestantes($agora),
            'total' => [
                'centavos' => $reserva->total->centavos,
                'moeda' => $reserva->total->moeda,
            ],
            'compradorEmail' => $reserva->compradorEmail,
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
     * @param array<string, mixed> $dados
     */
    private function inteiro(array $dados, string $campo): int
    {
        $valor = $dados[$campo] ?? null;

        if (!\is_int($valor)) {
            throw new \InvalidArgumentException(sprintf('O campo "%s" deve ser um número.', $campo));
        }

        return $valor;
    }
}
