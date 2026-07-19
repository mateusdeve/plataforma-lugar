# ADR-001 — Fronteira entre `Lote` e `Reserva`

**Status:** aceito
**Data:** julho de 2026

## Contexto

O invariante crítico do sistema é:

```
reservado + vendido <= total
```

Ele atravessa duas entidades. `Lote` conhece o total; `Reserva` é quem consome. Em DDD, invariante que atravessa entidades normalmente indica que elas pertencem ao mesmo agregado — e é essa conclusão que precisa ser examinada, não assumida.

## Opções

**A — `Lote` contém a coleção de `Reserva`.** O invariante é verificado em memória, dentro do agregado, do jeito canônico. Custo: para reservar um ingresso é preciso carregar todas as reservas do lote. Um evento com 5.000 reservas carrega 5.000 objetos para incrementar um número. O agregado cresce sem teto junto com o sucesso do evento — quanto melhor o produto funciona, pior ele fica.

**B — `Lote` guarda contadores; `Reserva` é agregado próprio referenciando `loteId` por identidade.** O invariante deixa de ser garantido pela estrutura do objeto e passa a ser garantido pela transação: lock pessimista no lote, recálculo da disponibilidade dentro do lock, rejeição se insuficiente.

## Decisão

**Opção B.**

## Consequências

O `Lote` é a única linha que precisa ser travada. `Reserva` é gravada livremente, sem contenção. A área de disputa do sistema fica reduzida a uma linha por lote — que é o mínimo possível, já que o estoque é literalmente um recurso disputado.

Em troca, o invariante deixa de ser evidente na leitura do código de domínio: ele vive no caso de uso, sob transação. Isso é uma perda real de expressividade, mitigada por duas coisas — o teste de concorrência que prova o comportamento sob disputa, e o `CHECK` no banco como última linha de defesa se a aplicação falhar.

Uma consequência que só apareceu depois: regras que atravessam `Reserva` e `Evento` — a RN-05, "máximo 2 reservas ativas por e-mail no mesmo evento" — não têm agregado onde morar. Ficam na camada de aplicação, verificadas dentro da mesma transação. É o preço da fronteira estreita, e é menor que o preço da opção A.
