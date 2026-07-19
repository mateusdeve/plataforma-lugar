<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Saude;

use Doctrine\DBAL\Connection;
use Lugar\Application\Saude\Verificacao;
use Lugar\Application\Saude\VerificadorDeDependencia;

/**
 * Aqui o Doctrine é permitido: esta é a camada que conhece infraestrutura.
 */
final readonly class VerificadorDoBanco implements VerificadorDeDependencia
{
    public function __construct(private Connection $conexao)
    {
    }

    public function verificar(): Verificacao
    {
        try {
            $this->conexao->executeQuery('SELECT 1')->fetchOne();

            return Verificacao::ok('banco', 'conectado');
        } catch (\Throwable) {
            // A mensagem do driver pode conter host, usuário e nome do banco.
            // Ela vai para o log, nunca para o corpo da resposta — /health é
            // público e não descreve a topologia interna.
            return Verificacao::falhou('banco', 'inalcançável');
        }
    }
}
