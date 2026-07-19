<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refresh tokens — a sessão longa, rotacionável e revogável.
 */
final class Version20260719140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tokens de renovação com hash, expiração e revogação.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE token_de_renovacao (
                -- A chave primária é o HASH SHA-256 do token, nunca o token.
                -- Se o banco vazar, o atacante leva hashes — inúteis para se
                -- passar por alguém, exatamente como acontece com senha.
                hash VARCHAR(64) NOT NULL,
                usuario_id VARCHAR(64) NOT NULL,
                criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expira_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                -- NULL enquanto válido. A rotação preenche este campo a cada
                -- uso, o que torna roubo de token detectável: se o furtado for
                -- usado, o legítimo para de funcionar.
                revogado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (hash),
                CONSTRAINT chk_token_prazo CHECK (expira_em > criado_em)
            )
            SQL);

        // Índice parcial, mesmo raciocínio da tabela reserva: só os tokens
        // ainda válidos são consultados, e revogados viram a maioria com o
        // tempo — indexá-los engordaria a árvore sem servir a ninguém.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_token_ativo_por_usuario
                ON token_de_renovacao (usuario_id)
             WHERE revogado_em IS NULL
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE token_de_renovacao
                ADD CONSTRAINT fk_token_usuario FOREIGN KEY (usuario_id)
                REFERENCES usuario (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS token_de_renovacao');
    }
}
