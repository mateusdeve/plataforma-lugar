<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Notificacao;

/**
 * Mensagem de fila: "mande o e-mail de confirmação desta compra".
 *
 * Leva os DADOS, não as entidades. Uma mensagem é serializada, guardada no
 * banco e consumida por outro processo minutos depois — entidade do Doctrine
 * não sobrevive a essa viagem, e mesmo que sobrevivesse estaria descrevendo um
 * estado que já mudou. O que o e-mail precisa dizer é o que era verdade no
 * momento da compra.
 */
final readonly class EnviarConfirmacaoDeCompra
{
    /**
     * @param list<string> $codigos
     */
    public function __construct(
        public string $reservaId,
        public string $compradorEmail,
        public array $codigos,
        public int $totalCentavos,
    ) {
    }
}
