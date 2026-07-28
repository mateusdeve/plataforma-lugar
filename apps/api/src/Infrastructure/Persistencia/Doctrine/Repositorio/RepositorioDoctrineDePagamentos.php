<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Repositorio;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Pagamento\Excecao\PagamentoJaRegistrado;
use Lugar\Domain\Pagamento\Pagamento;
use Lugar\Domain\Pagamento\RepositorioDePagamentos;

final readonly class RepositorioDoctrineDePagamentos implements RepositorioDePagamentos
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function buscarPorProvedorId(string $provedorId): ?Pagamento
    {
        return $this->em->getRepository(Pagamento::class)->findOneBy(['provedorId' => $provedorId]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * A VIOLAÇÃO DE UNICIDADE NÃO É UM ERRO — É A REGRA FUNCIONANDO.
     *
     * Quando dois webhooks do mesmo evento chegam ao mesmo tempo, os dois
     * passam pela consulta de idempotência (nenhum vê o outro, que ainda não
     * commitou) e os dois tentam gravar. O índice único deixa um passar.
     *
     * O segundo recebe `UniqueConstraintViolationException` — uma exceção de
     * driver, cheia de SQLSTATE e nome de constraint, que não deve subir até o
     * controller. Traduzir aqui é o trabalho desta camada: infraestrutura fala
     * Postgres, o resto do sistema fala domínio.
     *
     * O controller a transforma em 200, porque para o provedor a entrega
     * funcionou e o efeito já existe. Devolver erro faria ele reenviar em
     * backoff exponencial contra um endpoint que está certo.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function salvar(Pagamento $pagamento): void
    {
        try {
            $this->em->persist($pagamento);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new PagamentoJaRegistrado($pagamento->provedorId);
        }
    }
}
