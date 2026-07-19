<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * MIGRATIONS SOB LOCK GLOBAL.
 *
 * O PRD §7.3 diz "nunca automaticamente no boot", e o motivo é correto: dois
 * containers subindo ao mesmo tempo rodariam a mesma migration em paralelo, e
 * o resultado depende de quem chega primeiro no banco.
 *
 * A solução prevista era rodar como etapa do deploy, chamando o servidor de
 * fora. Isso caiu por um motivo prático: o EasyPanel não tem API documentada,
 * e chamada não documentada num pipeline que mexe em servidor com produção de
 * terceiros é dívida que vence sozinha.
 *
 * Então as migrations voltam para o boot — com a proteção que faltava.
 *
 * `pg_advisory_lock` é um lock global do Postgres, preso à SESSÃO que o
 * adquiriu. O segundo container a subir fica BLOQUEADO na linha do lock até o
 * primeiro terminar e a conexão fechar; então segue, encontra tudo aplicado e
 * não faz nada.
 *
 * O detalhe que faz funcionar: o lock e as migrations rodam na MESMA
 * `Connection`. Um `pg_advisory_lock` obtido em outra conexão seria liberado
 * assim que aquela conexão fechasse — inútil.
 *
 * Isso é mais forte que a proposta original do PRD, que dependia de ninguém
 * disparar dois deploys ao mesmo tempo. Aqui o banco garante.
 *
 * MORA EM Infrastructure/ E NÃO EM UI/
 *
 * O Deptrac barrou a primeira versão, que estava em UI/Console: aquela camada
 * não pode importar Doctrine. E a regra estava certa — um comando que fala
 * `pg_advisory_lock` não é interface com o usuário, é ferramenta de
 * infraestrutura. O `lugar:popular` continua em UI/Console porque só toca
 * portas do domínio.
 * ═══════════════════════════════════════════════════════════════════════════
 */
#[AsCommand(name: 'lugar:migrar', description: 'Aplica migrations sob lock global')]
final class MigrarCommand extends Command
{
    /**
     * Identificador arbitrário e fixo do lock. Qualquer número serve, desde
     * que seja o mesmo em todos os containers — é o que os faz disputar o
     * mesmo lock.
     */
    private const int CHAVE_DO_LOCK = 8_141_972;

    public function __construct(private readonly Connection $conexao)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $saida = new SymfonyStyle($input, $output);
        $aplicacao = $this->getApplication();

        if (null === $aplicacao) {
            throw new \LogicException('Comando sem aplicação.');
        }

        $saida->writeln('Aguardando o lock de migrations…');
        $this->conexao->executeStatement('SELECT pg_advisory_lock(?)', [self::CHAVE_DO_LOCK]);
        $saida->writeln('Lock obtido.');

        try {
            $codigo = $aplicacao->find('doctrine:migrations:migrate')->run(
                new ArrayInput([
                    '--no-interaction' => true,
                    // DDL é transacional no Postgres: uma migration que falhe
                    // no meio não deixa o esquema pela metade.
                    '--all-or-nothing' => true,
                    '--allow-no-migration' => true,
                ]),
                $output,
            );
        } finally {
            // `finally` e não depois do try: se a migration explodir, o lock
            // precisa sair mesmo assim, senão o próximo container espera para
            // sempre. (A conexão fechando também libera, mas contar com isso
            // é contar com sorte.)
            $this->conexao->executeStatement('SELECT pg_advisory_unlock(?)', [self::CHAVE_DO_LOCK]);
            $saida->writeln('Lock liberado.');
        }

        return $codigo;
    }
}
