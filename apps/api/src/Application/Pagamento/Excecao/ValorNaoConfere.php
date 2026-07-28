<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento\Excecao;

use Lugar\Domain\Comum\Excecao\ViolacaoDeRegraDeNegocio;

/**
 * O valor pago não quita a reserva.
 *
 * Não é erro de usuário: ninguém digita o valor. É divergência entre o que o
 * provedor cobrou e o que a reserva vale — configuração errada, moeda
 * trocada, ou alguém adulterando a cobrança antes de pagar. Vira 422 e um
 * registro de pagamento que fica no banco para a conciliação achar.
 */
final class ValorNaoConfere extends ViolacaoDeRegraDeNegocio
{
    public function __construct(
        public readonly int $esperadoEmCentavos,
        public readonly int $recebidoEmCentavos,
    ) {
        parent::__construct(
            sprintf(
                'A reserva vale %d centavos e o pagamento trouxe %d.',
                $esperadoEmCentavos,
                $recebidoEmCentavos,
            ),
        );
    }

    public function tipo(): string
    {
        return 'valor-nao-confere';
    }
}
