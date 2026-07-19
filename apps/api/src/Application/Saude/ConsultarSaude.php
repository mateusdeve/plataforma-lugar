<?php

declare(strict_types=1);

namespace Lugar\Application\Saude;

/**
 * Caso de uso do health check (PRD §6.5).
 *
 * Recebe os verificadores por injeção. Acrescentar uma dependência nova ao
 * sistema — Redis, S3, gateway — é criar uma classe em Infrastructure/ que
 * implemente a porta. Nada aqui muda.
 */
final readonly class ConsultarSaude
{
    /**
     * @param iterable<VerificadorDeDependencia> $verificadores
     */
    public function __construct(private iterable $verificadores)
    {
    }

    /**
     * @return list<Verificacao>
     */
    public function __invoke(): array
    {
        $resultados = [];

        foreach ($this->verificadores as $verificador) {
            $resultados[] = $verificador->verificar();
        }

        return $resultados;
    }
}
