<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Saude\ConsultarSaude;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check — PRD §6.5.
 *
 * Verifica banco E fila. A distinção importa: um health check que só responde
 * "estou de pé" mente. O processo pode estar vivo com o banco inalcançável, e
 * aí o orquestrador mantém em produção um container que não serve para nada.
 *
 * Este endpoint prova dependências, não existência. Responde 200 quando tudo
 * responde e 503 quando qualquer peça falha — para que o deploy pare antes de
 * mandar tráfego, e não depois.
 *
 * O controller não sabe o que é "banco" nem o que é "fila": ele pede o
 * resultado ao caso de uso e traduz para HTTP. É só isso que a camada UI faz.
 */
final readonly class HealthController
{
    public function __construct(private ConsultarSaude $consultarSaude)
    {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $verificacoes = ($this->consultarSaude)();

        $saudavel = array_all($verificacoes, static fn ($v): bool => $v->ok);

        return new JsonResponse(
            [
                'status' => $saudavel ? 'ok' : 'degradado',
                'verificacoes' => array_map(
                    static fn ($v): array => [
                        'nome' => $v->nome,
                        'ok' => $v->ok,
                        'detalhe' => $v->detalhe,
                    ],
                    $verificacoes,
                ),
            ],
            $saudavel ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
