<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Consulta;

use Doctrine\DBAL\Connection;
use Lugar\Application\Consulta\ConsultaDaPortaria;
use Lugar\Domain\Ingresso\StatusDoIngresso;

final readonly class ConsultaDoctrineDaPortaria implements ConsultaDaPortaria
{
    public function __construct(private Connection $conexao)
    {
    }

    public function porCodigo(string $codigo): ?array
    {
        $sql = <<<'SQL'
            SELECT i.codigo,
                   i.status,
                   i.utilizado_em,
                   r.quantidade,
                   r.comprador_email,
                   u.nome AS comprador_nome,
                   l.nome AS lote_nome,
                   e.id AS evento_id,
                   e.titulo AS evento_titulo
              FROM ingresso i
              JOIN reserva r ON r.id = i.reserva_id
              JOIN lote l ON l.id = r.lote_id
              JOIN evento e ON e.id = l.evento_id
              LEFT JOIN usuario u ON u.email = r.comprador_email
             WHERE i.codigo = :codigo
            SQL;

        $linha = $this->conexao
            ->executeQuery($sql, ['codigo' => mb_strtoupper(trim($codigo))])
            ->fetchAssociative();

        if (false === $linha) {
            return null;
        }

        return [
            'codigo' => $this->texto($linha['codigo']),
            'status' => $this->texto($linha['status']),
            'utilizadoEm' => null === $linha['utilizado_em'] ? null : $this->iso($linha['utilizado_em']),
            // Convidado não tem conta e portanto não tem nome (ADR-004). A tela
            // mostra o e-mail nesse caso — na porta, qualquer identificação
            // ajuda a resolver discussão.
            'compradorNome' => null === $linha['comprador_nome'] ? null : $this->texto($linha['comprador_nome']),
            'compradorEmail' => $this->texto($linha['comprador_email']),
            'loteNome' => $this->texto($linha['lote_nome']),
            'quantidade' => $this->inteiro($linha['quantidade']),
            'eventoId' => $this->texto($linha['evento_id']),
            'eventoTitulo' => $this->texto($linha['evento_titulo']),
        ];
    }

    public function entradasDoEvento(string $eventoId): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
              FROM ingresso i
              JOIN reserva r ON r.id = i.reserva_id
              JOIN lote l ON l.id = r.lote_id
             WHERE l.evento_id = :evento
               AND i.status = :utilizado
            SQL;

        return $this->inteiro($this->conexao->executeQuery($sql, [
            'evento' => $eventoId,
            'utilizado' => StatusDoIngresso::UTILIZADO->value,
        ])->fetchOne());
    }

    private function texto(mixed $valor): string
    {
        return \is_scalar($valor) ? (string) $valor : '';
    }

    private function inteiro(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    private function iso(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(\DATE_ATOM);
        }

        return (new \DateTimeImmutable($this->texto($valor)))->format(\DATE_ATOM);
    }
}
