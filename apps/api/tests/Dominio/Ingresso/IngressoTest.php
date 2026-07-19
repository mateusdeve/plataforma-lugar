<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Ingresso;

use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\Excecao\IngressoJaUtilizado;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\IngressoId;
use Lugar\Domain\Ingresso\StatusDoIngresso;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Tests\Dominio\Apoio\RelogioParado;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** RN-09 e RN-10. */
final class IngressoTest extends TestCase
{
    private function ingresso(RelogioParado $relogio): Ingresso
    {
        return Ingresso::emitir(
            new IngressoId('ing-1'),
            new ReservaId('reserva-1'),
            new CodigoIngresso('LGR-7Q2M-84KD'),
            $relogio->agora(),
        );
    }

    #[Test]
    #[TestDox('RN-10: a primeira leitura deixa entrar')]
    public function primeiraLeituraEntra(): void
    {
        $relogio = new RelogioParado('2026-09-12 19:42:00');
        $ingresso = $this->ingresso($relogio);

        $ingresso->utilizar($relogio->agora());

        self::assertTrue($ingresso->foiUtilizado());
        self::assertSame(StatusDoIngresso::UTILIZADO, $ingresso->status());
    }

    #[Test]
    #[TestDox('RN-10: a segunda leitura recusa e informa o horário da primeira')]
    public function segundaLeituraRecusaComHorario(): void
    {
        $relogio = new RelogioParado('2026-09-12 19:42:00');
        $ingresso = $this->ingresso($relogio);
        $ingresso->utilizar($relogio->agora());

        $relogio->avancar('+35 minutes');

        try {
            $ingresso->utilizar($relogio->agora());
            self::fail('Deveria ter lançado IngressoJaUtilizado.');
        } catch (IngressoJaUtilizado $erro) {
            // O horário é o da PRIMEIRA entrada, não o da tentativa. Na porta,
            // é o que distingue "clonaram seu print" de "você já entrou".
            self::assertSame('19:42', $erro->utilizadoEm->format('H:i'));
            self::assertStringContainsString('19h42', $erro->getMessage());
            self::assertSame('ingresso-ja-utilizado', $erro->tipo());
        }
    }

    #[Test]
    #[TestDox('RN-09: o código segue o formato LGR-XXXX-XXXX')]
    public function codigoValidoEAceito(): void
    {
        self::assertSame('LGR-8F3K-92QX', (new CodigoIngresso('LGR-8F3K-92QX'))->valor);
    }

    #[Test]
    #[TestDox('RN-09: o alfabeto exclui caracteres ambíguos (I, O, 0, 1, L)')]
    public function alfabetoNaoTemCaracteresAmbiguos(): void
    {
        foreach (['I', 'O', '0', '1', 'L'] as $ambiguo) {
            self::assertStringNotContainsString(
                $ambiguo,
                CodigoIngresso::ALFABETO,
                sprintf('O alfabeto não deveria conter "%s".', $ambiguo),
            );
        }
    }

    #[Test]
    #[TestDox('RN-09: código sequencial ou fora do padrão é recusado')]
    public function codigoInvalidoERecusado(): void
    {
        $invalidos = ['1', 'LGR-0001-0002', 'ABC-7Q2M-84KD', 'LGR-7Q2M', ''];
        $recusados = 0;

        foreach ($invalidos as $invalido) {
            try {
                new CodigoIngresso($invalido);
            } catch (\InvalidArgumentException) {
                ++$recusados;
            }
        }

        self::assertCount($recusados, $invalidos, 'Todo código fora do padrão deve ser recusado.');
    }
}
