<?php

declare(strict_types=1);

namespace Lugar\UI\Http\Controller;

use Lugar\Application\Consulta\ConsultaDeEventos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vitrine e detalhe. Ambos públicos: quem ainda não tem conta precisa ver os
 * eventos antes de decidir criar uma.
 */
final readonly class EventosController
{
    public function __construct(private ConsultaDeEventos $consulta)
    {
    }

    #[Route('/api/eventos', name: 'eventos_listar', methods: ['GET'])]
    public function listar(): JsonResponse
    {
        $resposta = new JsonResponse(['itens' => $this->consulta->publicados()]);

        // A vitrine pode estar até 5s desatualizada (PRD §6.4). A criação da
        // reserva, nunca — e ela não passa por aqui.
        $resposta->setPublic();
        $resposta->setMaxAge(5);

        return $resposta;
    }

    #[Route('/api/eventos/{id}', name: 'eventos_detalhe', methods: ['GET'])]
    public function detalhe(string $id): JsonResponse
    {
        $evento = $this->consulta->detalhe($id);

        if (null === $evento) {
            throw new NotFoundHttpException();
        }

        $resposta = new JsonResponse($evento, Response::HTTP_OK);
        $resposta->setPublic();
        $resposta->setMaxAge(5);

        return $resposta;
    }
}
