# ADR-002 — Expiração preguiçosa em vez de job de varredura

**Status:** aceito
**Data:** julho de 2026

## Contexto

Reserva retém estoque por 10 minutos. Quando o prazo vence, o estoque precisa voltar. A solução reflexa é um cron varrendo reservas vencidas e marcando-as como expiradas.

O problema do cron não é o custo — é a janela. Entre o instante em que a reserva vence e o instante em que a varredura roda, o estoque existe no mundo mas não existe no sistema. Um cron de minuto em minuto cria até 60 segundos em que o último ingresso está livre e ninguém consegue comprá-lo. Exatamente no momento em que mais gente está tentando.

Diminuir o intervalo reduz a janela sem eliminá-la, e aumenta a carga.

## Decisão

**Não existe job corrigindo estoque.** A disponibilidade é sempre derivada, no momento da leitura:

```sql
disponivel = quantidade_total
           - quantidade_vendida
           - (SELECT COALESCE(SUM(quantidade), 0)
              FROM reserva
              WHERE lote_id = :id
                AND status = 'PENDENTE'
                AND expira_em > NOW())
```

Uma reserva vencida deixa de reter estoque no exato instante em que vence, porque a condição `expira_em > NOW()` é avaliada a cada consulta. Não há estado a corrigir — o vencimento não é um evento que precisa ser processado, é uma propriedade do tempo.

## Consequências

Não existe janela de inconsistência. Não existe processo agendado para quebrar, monitorar ou depurar. O sistema funciona em infraestrutura que escala a zero, porque nada precisa estar rodando entre requisições.

O custo é que a query mais quente do sistema passa a ter um agregado. Mitigado pelo índice parcial `reserva (lote_id) WHERE status = 'PENDENTE'`, que mantém o conjunto varrido restrito às reservas que ainda podem importar — as pendentes de um lote são poucas por definição, já que expiram em minutos.

Uma decisão que segue desta: **não existe contador `quantidade_reservada`.** O PRD original o previa como cache desnormalizado. Foi removido. Um contador ao lado de uma query derivada é uma segunda verdade sobre o mesmo fato, e duas verdades só podem divergir. Se a leitura vier a doer, a discussão se reabre com medição na mão — não antes.

Um job noturno opcional pode marcar reservas vencidas como `EXPIRADA`, para higiene de dados e relatório. Ele nunca corrige estoque, e o sistema funciona igual se ele nunca rodar. Essa é a diferença entre conveniência e dependência.
