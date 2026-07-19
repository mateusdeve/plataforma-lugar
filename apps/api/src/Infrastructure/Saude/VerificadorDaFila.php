<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Saude;

use Doctrine\DBAL\Connection;
use Lugar\Application\Saude\Verificacao;
use Lugar\Application\Saude\VerificadorDeDependencia;

/**
 * A fila usa o transporte Doctrine (PRD §7.1), então verificar a fila é
 * verificar a tabela do transporte. Quando o transporte virar AMQP, é esta
 * classe que muda — e só ela.
 */
final readonly class VerificadorDaFila implements VerificadorDeDependencia
{
    private const TABELA = 'messenger_messages';

    public function __construct(private Connection $conexao)
    {
    }

    public function verificar(): Verificacao
    {
        try {
            if (!$this->conexao->createSchemaManager()->tablesExist([self::TABELA])) {
                return Verificacao::falhou('fila', 'tabela do transporte ausente');
            }

            // fetchOne() devolve mixed — o driver não promete o tipo. Um COUNT
            // pode voltar como string, então checar antes de converter.
            $resultado = $this->conexao->executeQuery(
                sprintf('SELECT COUNT(*) FROM %s WHERE delivered_at IS NULL', self::TABELA),
            )->fetchOne();

            $pendentes = is_numeric($resultado) ? (int) $resultado : 0;

            return Verificacao::ok('fila', sprintf('%d mensagens pendentes', $pendentes));
        } catch (\Throwable) {
            return Verificacao::falhou('fila', 'inalcançável');
        }
    }
}
