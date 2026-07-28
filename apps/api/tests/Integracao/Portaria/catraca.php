<?php

declare(strict_types=1);

/*
 * Uma catraca tentando validar o mesmo ingresso.
 *
 * Roda como PROCESSO SEPARADO, com sua própria conexão ao Postgres — a mesma
 * razão do `concorrente.php` da fase 2: lock pessimista é comportamento do
 * banco, e provar que funciona exige conexões simultâneas de verdade.
 *
 * O cenário real: o print do ingresso circula no grupo da família e duas
 * pessoas chegam em catracas diferentes ao mesmo tempo.
 *
 * Uso: php catraca.php <codigo> <eventoId> <instanteDaLargada>
 *
 * Imprime uma palavra e sai:
 *   ENTROU    validou a entrada
 *   JA_USADO  recusado por RN-10 (o esperado para todos menos um)
 *   ERRO:...  qualquer outra coisa — e qualquer outra coisa é um problema
 */

use Lugar\Application\Portaria\ValidarIngresso;
use Lugar\Domain\Ingresso\Excecao\IngressoJaUtilizado;
use Lugar\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

[$script, $codigo, $eventoId, $largada] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();

$containerDeTeste = $kernel->getContainer()->get('test.service_container');
assert($containerDeTeste instanceof Psr\Container\ContainerInterface);

$validar = $containerDeTeste->get(ValidarIngresso::class);
assert($validar instanceof ValidarIngresso);

// A largada — ver o comentário longo em concorrente.php.
$alvo = (float) $largada;
while (microtime(true) < $alvo) {
    // Espera ativa: usleep não tem resolução para alinhar na casa do ms.
}

try {
    $validar($codigo, $eventoId);

    echo 'ENTROU';
} catch (IngressoJaUtilizado) {
    echo 'JA_USADO';
} catch (Throwable $erro) {
    echo 'ERRO:'.get_class($erro).':'.$erro->getMessage();
}
