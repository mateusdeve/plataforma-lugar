<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao;

use Doctrine\DBAL\Connection;
use Lugar\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Teste de fumaça da fase 0.
 *
 * Não valida regra de negócio — não há nenhuma ainda. Valida que o container
 * de injeção de dependência compila e que os serviços essenciais são
 * construíveis.
 *
 * Parece pouco, e paga sozinho: erro de configuração em Symfony só aparece no
 * boot, e sem este teste ele apareceria em produção, no primeiro request
 * depois do deploy. Foi exatamente assim que as opções removidas do
 * DoctrineBundle 3 foram descobertas aqui.
 */
final class ContainerCompilaTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOContainerCompila(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->has('doctrine'));
    }

    public function testAConexaoComOBancoEConstruivel(): void
    {
        self::bootKernel();

        $conexao = self::getContainer()->get(Connection::class);

        self::assertInstanceOf(Connection::class, $conexao);
    }
}
