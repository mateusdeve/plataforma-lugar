<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;

final readonly class RepositorioDoctrineDeEventos implements RepositorioDeEventos
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscar(EventoId $id): ?Evento
    {
        return $this->em->find(Evento::class, $id);
    }

    public function salvar(Evento $evento): void
    {
        $this->em->persist($evento);
        $this->em->flush();
    }

    /**
     * SQL direto, na ordem inversa das chaves estrangeiras: pagamento e
     * ingresso apontam para reserva, reserva para lote, lote para evento.
     * `evento_operador` cai sozinho (ON DELETE CASCADE).
     *
     * As reservas apagadas aqui nunca foram confirmadas — a RN-12, verificada
     * pelo caso de uso NA MESMA transação, garante isso antes de chegar aqui.
     */
    public function excluir(Evento $evento): void
    {
        $conexao = $this->em->getConnection();
        $parametros = ['evento' => $evento->id->valor];

        $reservasDoEvento = <<<'SQL'
            SELECT r.id FROM reserva r
              JOIN lote l ON l.id = r.lote_id
             WHERE l.evento_id = :evento
            SQL;

        $conexao->executeStatement(
            sprintf('DELETE FROM pagamento WHERE reserva_id IN (%s)', $reservasDoEvento),
            $parametros,
        );
        $conexao->executeStatement(
            sprintf('DELETE FROM ingresso WHERE reserva_id IN (%s)', $reservasDoEvento),
            $parametros,
        );
        $conexao->executeStatement(
            'DELETE FROM reserva WHERE lote_id IN (SELECT id FROM lote WHERE evento_id = :evento)',
            $parametros,
        );
        $conexao->executeStatement('DELETE FROM lote WHERE evento_id = :evento', $parametros);
        $conexao->executeStatement('DELETE FROM evento WHERE id = :evento', $parametros);

        // O objeto continua no Identity Map; sem isto, um flush posterior
        // tentaria UPDATE numa linha que não existe mais.
        $this->em->detach($evento);
    }
}
