<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\DBAL\Connection;
use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Usuario\UsuarioId;

/**
 * `evento_operador` é uma tabela de ligação pura — sem comportamento, sem
 * identidade própria. Mapear como entidade Doctrine seria cerimônia sem
 * retorno; SQL direto é mais honesto e mais rápido.
 */
final readonly class EscalaDePortariaDoctrine implements EscalaDePortaria
{
    public function __construct(private Connection $conexao)
    {
    }

    public function estaEscalado(UsuarioId $usuarioId, EventoId $eventoId): bool
    {
        $existe = $this->conexao->executeQuery(
            'SELECT 1 FROM evento_operador WHERE usuario_id = :u AND evento_id = :e LIMIT 1',
            ['u' => $usuarioId->valor, 'e' => $eventoId->valor],
        )->fetchOne();

        return false !== $existe && null !== $existe;
    }

    public function escalar(UsuarioId $usuarioId, EventoId $eventoId): void
    {
        // ON CONFLICT DO NOTHING: escalar duas vezes é intenção idempotente,
        // não erro. Quem clicou duas vezes queria a mesma coisa.
        $this->conexao->executeStatement(
            'INSERT INTO evento_operador (evento_id, usuario_id, criado_em)
             VALUES (:e, :u, NOW())
             ON CONFLICT (evento_id, usuario_id) DO NOTHING',
            ['u' => $usuarioId->valor, 'e' => $eventoId->valor],
        );
    }

    public function retirarDaEscala(UsuarioId $usuarioId, EventoId $eventoId): void
    {
        $this->conexao->executeStatement(
            'DELETE FROM evento_operador WHERE usuario_id = :u AND evento_id = :e',
            ['u' => $usuarioId->valor, 'e' => $eventoId->valor],
        );
    }

    public function operadoresDo(EventoId $eventoId): array
    {
        $linhas = $this->conexao->executeQuery(
            'SELECT usuario_id FROM evento_operador WHERE evento_id = :e ORDER BY criado_em',
            ['e' => $eventoId->valor],
        )->fetchFirstColumn();

        return array_values(array_map(
            static function (mixed $id): UsuarioId {
                if (!\is_string($id)) {
                    throw new \RuntimeException('usuario_id deve vir do banco como texto.');
                }

                return new UsuarioId($id);
            },
            $linhas,
        ));
    }
}
