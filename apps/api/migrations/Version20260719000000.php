<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Esquema inicial — PRD §8.
 *
 * Escrita à mão, e não gerada por `doctrine:migrations:diff`, porque as três
 * coisas mais importantes deste arquivo o gerador não produz: o CHECK do
 * invariante, o índice parcial e as chaves estrangeiras entre agregados.
 */
final class Version20260719000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Esquema inicial: evento, lote, reserva, ingresso, pagamento e auditoria.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Esta migration usa índice parcial e CHECK — recursos de PostgreSQL.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE evento (
                id VARCHAR(64) NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                local VARCHAR(200) NOT NULL,
                cidade VARCHAR(120) NOT NULL,
                inicia_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                descricao TEXT NOT NULL,
                prazo_reserva_minutos SMALLINT NOT NULL,
                status VARCHAR(20) NOT NULL,
                PRIMARY KEY (id),
                -- RN-01: o prazo é configurável, mas dentro de limites.
                CONSTRAINT chk_evento_prazo CHECK (prazo_reserva_minutos BETWEEN 5 AND 30)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE lote (
                id VARCHAR(64) NOT NULL,
                evento_id VARCHAR(64) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                quantidade_total INT NOT NULL,
                quantidade_vendida INT NOT NULL,
                preco_centavos INT NOT NULL,
                preco_moeda VARCHAR(3) NOT NULL,
                vendas_iniciam_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                vendas_terminam_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),

                -- ═══════════════════════════════════════════════════════════
                -- A ÚLTIMA LINHA DE DEFESA DO INVARIANTE.
                --
                -- A aplicação já garante isto no caso de uso, sob lock
                -- pessimista. Este CHECK existe para o dia em que ela falhar:
                -- um bug, uma migration mal feita, alguém rodando UPDATE na
                -- mão em produção às duas da manhã.
                --
                -- Se acontecer, o banco RECUSA a escrita em vez de aceitar um
                -- estado impossível. É a diferença entre um erro e um evento
                -- com 501 pessoas para 500 lugares.
                -- ═══════════════════════════════════════════════════════════
                CONSTRAINT chk_lote_vendido_ate_total CHECK (quantidade_vendida <= quantidade_total),
                CONSTRAINT chk_lote_nao_negativo CHECK (quantidade_vendida >= 0 AND quantidade_total > 0),
                -- Dinheiro em centavos, inteiro, nunca negativo (PRD §8).
                CONSTRAINT chk_lote_preco CHECK (preco_centavos >= 0)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reserva (
                id VARCHAR(64) NOT NULL,
                lote_id VARCHAR(64) NOT NULL,
                comprador_email VARCHAR(180) NOT NULL,
                quantidade SMALLINT NOT NULL,
                status VARCHAR(20) NOT NULL,
                criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expira_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                idempotency_key VARCHAR(64) DEFAULT NULL,
                total_centavos INT NOT NULL,
                total_moeda VARCHAR(3) NOT NULL,
                PRIMARY KEY (id),
                -- RN-04: máximo 6 ingressos por reserva.
                CONSTRAINT chk_reserva_quantidade CHECK (quantidade BETWEEN 1 AND 6),
                CONSTRAINT chk_reserva_prazo CHECK (expira_em > criado_em)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ingresso (
                id VARCHAR(64) NOT NULL,
                reserva_id VARCHAR(64) NOT NULL,
                codigo VARCHAR(13) NOT NULL,
                status VARCHAR(20) NOT NULL,
                emitido_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                utilizado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                -- RN-10: se está utilizado, tem de haver horário. E vice-versa.
                CONSTRAINT chk_ingresso_utilizado_coerente CHECK (
                    (status = 'UTILIZADO' AND utilizado_em IS NOT NULL)
                    OR (status <> 'UTILIZADO' AND utilizado_em IS NULL)
                )
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE pagamento (
                id VARCHAR(64) NOT NULL,
                reserva_id VARCHAR(64) NOT NULL,
                provedor_id VARCHAR(180) NOT NULL,
                valor_centavos INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                payload_bruto JSONB NOT NULL,
                recebido_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE auditoria (
                id BIGSERIAL NOT NULL,
                entidade VARCHAR(40) NOT NULL,
                entidade_id VARCHAR(64) NOT NULL,
                estado_de VARCHAR(20) DEFAULT NULL,
                estado_para VARCHAR(20) NOT NULL,
                ator VARCHAR(180) DEFAULT NULL,
                ocorrido_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // ── índices ──────────────────────────────────────────────────────

        // ═════════════════════════════════════════════════════════════════
        // O ÍNDICE DO CAMINHO CRÍTICO.
        //
        // A query de disponibilidade (ADR-002) roda dentro do lock, em toda
        // reserva. Ela filtra por lote_id e status = 'PENDENTE'.
        //
        // O índice é PARCIAL de propósito. Com o tempo, a esmagadora maioria
        // das reservas estará CONFIRMADA ou EXPIRADA — e nenhuma delas
        // aparece nesta query. Indexá-las engordaria a árvore, deixaria a
        // escrita mais cara e a leitura mais lenta, sem servir a ninguém.
        //
        // O índice cobre só o que a consulta enxerga, e por isso permanece
        // pequeno mesmo depois de um milhão de ingressos vendidos.
        // ═════════════════════════════════════════════════════════════════
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_reserva_pendente_por_lote
                ON reserva (lote_id, expira_em)
             WHERE status = 'PENDENTE'
            SQL);

        // Idempotência garantida no banco, não só no código (PRD §6.2).
        // Duas requisições simultâneas com a mesma chave: uma grava, a outra
        // recebe violação de unicidade — nunca duas reservas.
        $this->addSql('CREATE UNIQUE INDEX uniq_reserva_idempotency ON reserva (idempotency_key)');

        // RN-09: o código é a credencial de entrada. Dois iguais seria duas
        // pessoas com direito ao mesmo lugar.
        $this->addSql('CREATE UNIQUE INDEX uniq_ingresso_codigo ON ingresso (codigo)');

        // É isto que torna o webhook do gateway idempotente no nível do banco
        // (PRD §8): reprocessar o mesmo evento não duplica pagamento.
        $this->addSql('CREATE UNIQUE INDEX uniq_pagamento_provedor ON pagamento (provedor_id)');

        $this->addSql('CREATE INDEX idx_lote_por_evento ON lote (evento_id)');
        $this->addSql('CREATE INDEX idx_ingresso_por_reserva ON ingresso (reserva_id)');
        $this->addSql('CREATE INDEX idx_auditoria_entidade ON auditoria (entidade, entidade_id)');

        // ── integridade referencial ──────────────────────────────────────
        // Agregados se referenciam por identidade no CÓDIGO (ADR-001), mas o
        // banco continua sendo o guardião da integridade. Sem estas chaves,
        // um lote órfão apontando para um evento inexistente seria possível.
        $this->addSql('ALTER TABLE lote ADD CONSTRAINT fk_lote_evento FOREIGN KEY (evento_id) REFERENCES evento (id)');
        $this->addSql('ALTER TABLE reserva ADD CONSTRAINT fk_reserva_lote FOREIGN KEY (lote_id) REFERENCES lote (id)');
        $this->addSql('ALTER TABLE ingresso ADD CONSTRAINT fk_ingresso_reserva FOREIGN KEY (reserva_id) REFERENCES reserva (id)');
        $this->addSql('ALTER TABLE pagamento ADD CONSTRAINT fk_pagamento_reserva FOREIGN KEY (reserva_id) REFERENCES reserva (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS auditoria');
        $this->addSql('DROP TABLE IF EXISTS pagamento');
        $this->addSql('DROP TABLE IF EXISTS ingresso');
        $this->addSql('DROP TABLE IF EXISTS reserva');
        $this->addSql('DROP TABLE IF EXISTS lote');
        $this->addSql('DROP TABLE IF EXISTS evento');
    }
}
