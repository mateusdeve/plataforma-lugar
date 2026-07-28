<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

/**
 * O que o organizador preencheu no formulário, já tipado.
 *
 * `precoCentavos` é inteiro porque `Dinheiro` é inteiro (fase 1): o front
 * converte "R$ 180" em 18000 antes de mandar, e nenhuma camada daqui para
 * baixo volta a ver ponto flutuante.
 *
 * @phpstan-type LoteNovo array{nome: string, precoCentavos: int, quantidade: int}
 */
final readonly class CriarEventoComando
{
    /**
     * @param list<array{nome: string, precoCentavos: int, quantidade: int}> $lotes
     */
    public function __construct(
        public string $organizadorId,
        public string $titulo,
        public string $local,
        public string $cidade,
        public \DateTimeImmutable $iniciaEm,
        public string $descricao,
        public int $prazoReservaMinutos,
        public array $lotes,
    ) {
    }
}
