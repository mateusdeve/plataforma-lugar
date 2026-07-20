<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Consulta;

use Doctrine\DBAL\Connection;
use Lugar\Application\Consulta\ConsultaDeIngressos;

final readonly class ConsultaDoctrineDeIngressos implements ConsultaDeIngressos
{
    public function __construct(private Connection $conexao)
    {
    }

    public function daReserva(string $reservaId): array
    {
        $sql = <<<'SQL'
            SELECT i.codigo,
                   i.status,
                   i.utilizado_em,
                   r.comprador_email,
                   r.quantidade,
                   l.nome AS lote_nome,
                   e.titulo AS evento_titulo,
                   e.inicia_em AS evento_inicia_em,
                   e.local AS evento_local,
                   e.cidade AS evento_cidade
              FROM ingresso i
              JOIN reserva r ON r.id = i.reserva_id
              JOIN lote l ON l.id = r.lote_id
              JOIN evento e ON e.id = l.evento_id
             WHERE i.reserva_id = :reserva
             ORDER BY i.emitido_em, i.codigo
            SQL;

        return array_map(
            fn (array $linha): array => [
                'codigo' => $this->texto($linha['codigo']),
                'status' => $this->texto($linha['status']),
                'utilizadoEm' => null === $linha['utilizado_em'] ? null : $this->iso($linha['utilizado_em']),
                'compradorEmail' => $this->texto($linha['comprador_email']),
                'compradorNome' => null,
                'quantidade' => $this->inteiro($linha['quantidade']),
                'loteNome' => $this->texto($linha['lote_nome']),
                'eventoTitulo' => $this->texto($linha['evento_titulo']),
                'eventoIniciaEm' => $this->iso($linha['evento_inicia_em']),
                'eventoLocal' => sprintf(
                    '%s, %s',
                    $this->texto($linha['evento_local']),
                    $this->texto($linha['evento_cidade']),
                ),
            ],
            $this->conexao->executeQuery($sql, ['reserva' => $reservaId])->fetchAllAssociative(),
        );
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
