<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Comum;

use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Usuario\UsuarioId;
use Symfony\Component\Uid\Uuid;

final readonly class GeradorDeIdentidadePadrao implements GeradorDeIdentidade
{
    /**
     * UUID v7 e não v4: o v7 começa com timestamp, então ids gerados em
     * sequência ficam próximos no índice B-tree. Com v4, cada inserção cai num
     * ponto aleatório da árvore e o índice fragmenta.
     */
    public function novaReservaId(): ReservaId
    {
        return new ReservaId((string) Uuid::v7());
    }

    public function novoUsuarioId(): UsuarioId
    {
        return new UsuarioId((string) Uuid::v7());
    }

    /**
     * RN-09: aleatório e não adivinhável.
     *
     * `random_int` e não `rand`: o segundo é previsível a partir de algumas
     * amostras. Aqui o código É a credencial de entrada no evento — adivinhar
     * um é entrar no lugar de outra pessoa.
     */
    public function novoCodigoDeIngresso(): CodigoIngresso
    {
        $alfabeto = CodigoIngresso::ALFABETO;
        $ultimo = \strlen($alfabeto) - 1;

        $bloco = static function () use ($alfabeto, $ultimo): string {
            $saida = '';
            for ($i = 0; $i < 4; ++$i) {
                $saida .= $alfabeto[random_int(0, $ultimo)];
            }

            return $saida;
        };

        return new CodigoIngresso(sprintf('LGR-%s-%s', $bloco(), $bloco()));
    }
}
