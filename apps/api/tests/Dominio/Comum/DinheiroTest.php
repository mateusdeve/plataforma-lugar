<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Comum;

use Lugar\Domain\Comum\Dinheiro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DinheiroTest extends TestCase
{
    #[Test]
    public function multiplicarNaoPerdeCentavos(): void
    {
        // O caso clássico que float erra: 0,10 somado dez vezes.
        $dezCentavos = Dinheiro::emCentavos(10);

        self::assertSame(100, $dezCentavos->multiplicadoPor(10)->centavos);
    }

    #[Test]
    public function doisIngressosDe220ReaisSao440Reais(): void
    {
        $preco = Dinheiro::emCentavos(22_000);

        self::assertSame(44_000, $preco->multiplicadoPor(2)->centavos);
    }

    #[Test]
    public function naoAceitaValorNegativo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinheiro::emCentavos(-1);
    }

    #[Test]
    public function naoSomaMoedasDiferentes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinheiro::emCentavos(100, 'BRL')->somadoA(Dinheiro::emCentavos(100, 'USD'));
    }
}
