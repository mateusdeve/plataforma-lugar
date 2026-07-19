<?php

declare(strict_types=1);

/*
 * Um concorrente na disputa pelo último ingresso.
 *
 * Roda como PROCESSO SEPARADO, com sua própria conexão ao Postgres. É essa
 * separação que torna o teste honesto: o lock pessimista é comportamento do
 * banco, e provar que funciona exige conexões simultâneas de verdade, não
 * chamadas em sequência dentro do mesmo processo.
 *
 * Uso: php concorrente.php <loteId> <email> <quantidade> <instanteDaLargada>
 *
 * Imprime uma palavra e sai:
 *   OK       conseguiu reservar
 *   ESTOQUE  recusado por estoque insuficiente (o 409 esperado)
 *   ERRO:... qualquer outra coisa — e qualquer outra coisa é um problema
 */

use Lugar\Application\Reserva\CriarReserva;
use Lugar\Application\Reserva\CriarReservaComando;
use Lugar\Domain\Lote\Excecao\EstoqueInsuficiente;
use Lugar\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');

[$script, $loteId, $email, $quantidade, $largada] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();

$containerDeTeste = $kernel->getContainer()->get('test.service_container');
assert($containerDeTeste instanceof Psr\Container\ContainerInterface);

$criarReserva = $containerDeTeste->get(CriarReserva::class);
assert($criarReserva instanceof CriarReserva);

// A LARGADA.
//
// Todos os processos recebem o mesmo instante futuro e ficam em espera ativa
// até ele chegar. Sem isso, o custo de boot do Symfony (dezenas de
// milissegundos, e diferente para cada processo) espalharia as tentativas no
// tempo — o primeiro terminaria antes do último começar e não haveria disputa
// nenhuma para o lock resolver.
$alvo = (float) $largada;
while (microtime(true) < $alvo) {
    // Espera ativa de propósito: usleep tem resolução grosseira demais para
    // alinhar processos na casa do milissegundo.
}

try {
    $criarReserva(new CriarReservaComando(
        loteId: $loteId,
        compradorEmail: $email,
        quantidade: (int) $quantidade,
    ));

    echo 'OK';
} catch (EstoqueInsuficiente) {
    echo 'ESTOQUE';
} catch (Throwable $erro) {
    echo 'ERRO:'.get_class($erro).':'.$erro->getMessage();
}
