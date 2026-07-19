<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identidade e acesso — ADR-004.
 *
 * Duas tabelas e uma coluna. A coluna é a mais importante das três:
 * `evento.organizador_id` é o vínculo que transforma "pode organizar" em
 * "pode organizar ESTE evento".
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Usuários, papéis acumuláveis e escala da portaria.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE usuario (
                id VARCHAR(64) NOT NULL,
                email VARCHAR(180) NOT NULL,
                senha_hash VARCHAR(255) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                -- Papéis acumuláveis (ADR-004): um usuário pode ser
                -- organizador e portaria ao mesmo tempo.
                papeis JSON NOT NULL,
                criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // Sem UNIQUE aqui, dois cadastros simultâneos com o mesmo e-mail
        // criariam duas contas — e a segunda ficaria inacessível, porque o
        // login busca por e-mail e encontraria sempre a primeira.
        $this->addSql('CREATE UNIQUE INDEX uniq_usuario_email ON usuario (email)');

        $this->addSql(<<<'SQL'
            CREATE TABLE evento_operador (
                evento_id VARCHAR(64) NOT NULL,
                usuario_id VARCHAR(64) NOT NULL,
                criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                -- Chave composta: a mesma pessoa não é escalada duas vezes no
                -- mesmo evento, e o INSERT pode usar ON CONFLICT DO NOTHING.
                PRIMARY KEY (evento_id, usuario_id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_operador_por_usuario ON evento_operador (usuario_id)');

        // ═════════════════════════════════════════════════════════════════
        // O VÍNCULO.
        //
        // Sem esta coluna, ROLE_ORGANIZADOR autorizaria ler o painel, os
        // compradores e o faturamento de QUALQUER evento. É a falha de
        // autorização mais comum que existe, e ela não aparece na tela —
        // aparece em quem chama a API direto.
        // ═════════════════════════════════════════════════════════════════
        $this->addSql('ALTER TABLE evento ADD organizador_id VARCHAR(64) NOT NULL');
        $this->addSql('CREATE INDEX idx_evento_por_organizador ON evento (organizador_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE evento_operador
                ADD CONSTRAINT fk_operador_evento FOREIGN KEY (evento_id)
                REFERENCES evento (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE evento_operador
                ADD CONSTRAINT fk_operador_usuario FOREIGN KEY (usuario_id)
                REFERENCES usuario (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE evento
                ADD CONSTRAINT fk_evento_organizador FOREIGN KEY (organizador_id)
                REFERENCES usuario (id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evento DROP CONSTRAINT IF EXISTS fk_evento_organizador');
        $this->addSql('DROP INDEX IF EXISTS idx_evento_por_organizador');
        $this->addSql('ALTER TABLE evento DROP COLUMN IF EXISTS organizador_id');
        $this->addSql('DROP TABLE IF EXISTS evento_operador');
        $this->addSql('DROP TABLE IF EXISTS usuario');
    }
}
