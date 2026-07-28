<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajusta `pagamento` para receber a fase 5.
 *
 * Duas mudanças, e a segunda é a que importa.
 */
final class Version20260719160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pagamento: moeda explícita e payload preservado byte a byte.';
    }

    public function up(Schema $schema): void
    {
        /*
          1. Moeda explícita.

          As outras tabelas de dinheiro já guardam a moeda ao lado do valor
          (`lote.preco_moeda`, `reserva.total_moeda`). `pagamento` tinha só os
          centavos, o que obrigaria a assumir BRL no código — e uma constante
          escondida numa consulta é onde erro de moeda se esconde.
        */
        $this->addSql("ALTER TABLE pagamento ADD valor_moeda VARCHAR(3) NOT NULL DEFAULT 'BRL'");
        $this->addSql('ALTER TABLE pagamento ALTER COLUMN valor_moeda DROP DEFAULT');

        /*
          ═══════════════════════════════════════════════════════════════════
          2. JSONB → TEXT. Parece um retrocesso e é o contrário.

          O payload é guardado para o dia em que um pagamento for contestado:
          o que resolve a discussão é exatamente o que o provedor mandou.

          JSONB não preserva isso. Ele faz *parse* e regrava numa forma
          binária normalizada — reordena as chaves, descarta espaços e
          duplicatas. O que sai não é o que entrou.

          Duas consequências práticas:

            · a "prova" deixa de ser prova, porque foi reescrita por nós;
            · a assinatura HMAC não pode mais ser reconferida sobre o payload
              guardado, já que o HMAC é sobre os bytes originais. Um byte
              diferente, hash diferente.

          TEXT guarda a sequência de bytes que chegou. É o que a coluna sempre
          quis ser. Consultar dentro do JSON não é caso de uso aqui — e, se um
          dia for, `payload_bruto::jsonb` resolve na consulta sem sacrificar o
          original.
          ═══════════════════════════════════════════════════════════════════
        */
        $this->addSql('ALTER TABLE pagamento ALTER COLUMN payload_bruto TYPE TEXT USING payload_bruto::text');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pagamento ALTER COLUMN payload_bruto TYPE JSONB USING payload_bruto::jsonb');
        $this->addSql('ALTER TABLE pagamento DROP COLUMN valor_moeda');
    }
}
