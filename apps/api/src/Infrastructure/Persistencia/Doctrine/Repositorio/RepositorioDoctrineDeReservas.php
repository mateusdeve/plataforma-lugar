<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
use Lugar\Domain\Reserva\StatusDaReserva;

final readonly class RepositorioDoctrineDeReservas implements RepositorioDeReservas
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscar(ReservaId $id): ?Reserva
    {
        return $this->em->find(Reserva::class, $id);
    }

    public function salvar(Reserva $reserva): void
    {
        $this->em->persist($reserva);
        $this->em->flush();
    }

    /**
     * A query do ADR-002 — a expiração preguiçosa em SQL.
     *
     *   status = 'PENDENTE' AND expira_em > :agora
     *
     * A segunda condição é a decisão inteira do ADR: uma reserva vencida
     * deixa de reter estoque no exato instante em que vence, sem que nada a
     * processe. Não há job, não há janela de inconsistência, e o sistema
     * funciona igual se nenhum processo em segundo plano rodar.
     *
     * Roda em SQL direto e não em DQL de propósito: está no caminho crítico
     * de toda reserva, dentro do lock, e aqui não se paga hidratação de
     * objetos para somar um inteiro.
     */
    public function contarUnidadesAtivasNoLote(LoteId $loteId, \DateTimeImmutable $agora): int
    {
        $sql = <<<'SQL'
            SELECT COALESCE(SUM(quantidade), 0)
              FROM reserva
             WHERE lote_id = :lote
               AND status = :pendente
               AND expira_em > :agora
            SQL;

        $resultado = $this->em->getConnection()->executeQuery($sql, [
            'lote' => $loteId->valor,
            'pendente' => StatusDaReserva::PENDENTE->value,
            'agora' => $agora->format('Y-m-d H:i:s'),
        ])->fetchOne();

        return is_numeric($resultado) ? (int) $resultado : 0;
    }

    /**
     * RN-05. Atravessa reserva → lote → evento, e por isso não é invariante de
     * agregado (ADR-001) — a regra vive no caso de uso, não no domínio.
     */
    public function contarReservasAtivasDoCompradorNoEvento(
        string $email,
        EventoId $eventoId,
        \DateTimeImmutable $agora,
    ): int {
        $sql = <<<'SQL'
            SELECT COUNT(*)
              FROM reserva r
              JOIN lote l ON l.id = r.lote_id
             WHERE r.comprador_email = :email
               AND l.evento_id = :evento
               AND r.status = :pendente
               AND r.expira_em > :agora
            SQL;

        $resultado = $this->em->getConnection()->executeQuery($sql, [
            'email' => $email,
            'evento' => $eventoId->valor,
            'pendente' => StatusDaReserva::PENDENTE->value,
            'agora' => $agora->format('Y-m-d H:i:s'),
        ])->fetchOne();

        return is_numeric($resultado) ? (int) $resultado : 0;
    }

    public function buscarPorChaveDeIdempotencia(string $chave): ?Reserva
    {
        $sql = 'SELECT id FROM reserva WHERE idempotency_key = :chave LIMIT 1';

        $id = $this->em->getConnection()
            ->executeQuery($sql, ['chave' => $chave], ['chave' => ParameterType::STRING])
            ->fetchOne();

        return \is_string($id) ? $this->buscar(new ReservaId($id)) : null;
    }
}
