# PRD — Plataforma de Venda de Ingressos com Reserva Temporária

**Versão:** 1.0
**Status:** Rascunho para discussão
**Autor:** —
**Última atualização:** julho de 2026

---

## 1. Contexto e objetivo

### 1.1 Objetivo do produto

Permitir que organizadores publiquem eventos com lotes de ingressos limitados, e que compradores garantam seu lugar por meio de uma **reserva temporária** enquanto concluem o pagamento — sem que o mesmo ingresso seja vendido duas vezes.

### 1.2 Objetivo real do projeto

Este é um projeto de portfólio. O produto precisa funcionar de verdade, mas a métrica de sucesso não é usuário: é **demonstrar competência técnica verificável** em Symfony/DDD, Next.js/TypeScript, PostgreSQL e controle de concorrência.

Isso implica duas regras que valem para todo o documento:

- Qualquer funcionalidade que não exercite uma dessas competências é candidata a corte.
- Toda decisão de arquitetura precisa de justificativa escrita (ADR). Um repositório com decisões documentadas vale mais que um repositório com mais telas.

### 1.3 O problema central

Venda de ingresso é um problema de **estoque sob concorrência**. Dois usuários clicam em "comprar" no último ingresso no mesmo milissegundo. Sem tratamento, o banco aceita os dois e o evento tem 501 pessoas para 500 lugares.

A reserva temporária resolve isso de forma humana: o estoque é retirado de circulação por 10 minutos enquanto o comprador paga. Se pagar, vira venda. Se não, volta para o estoque.

---

## 2. Escopo

### 2.1 Dentro do MVP

- Cadastro e publicação de eventos com múltiplos lotes
- Listagem pública de eventos e disponibilidade em tempo quase real
- Reserva temporária com expiração
- Checkout com gateway em sandbox
- Emissão de ingresso com código único
- Painel do organizador com vendas e ocupação
- Validação de ingresso na entrada (leitura do código)

### 2.2 Fora do MVP (explicitamente)

| Item                                  | Por quê                                                      |
| ------------------------------------- | ------------------------------------------------------------ |
| Fila de espera                        | Fase 3. Dobra a complexidade do domínio sem provar nada novo |
| Mapa de assentos numerados            | Muda o modelo inteiro de estoque. Vira outro produto         |
| Reembolso e estorno                   | Estado a mais na máquina, pouco retorno demonstrativo        |
| Multi-moeda e impostos                | Ruído                                                        |
| App mobile                            | Web responsiva basta                                         |
| Marketplace / repasse a organizadores | Outro domínio inteiro                                        |

Escopo cortado é escopo entregue. Um MVP fechado e polido comunica critério; dez features pela metade comunicam o contrário.

---

## 3. Personas

**Comprador (Ana, 28)** — quer o ingresso antes de acabar. Tolera 10 minutos de prazo, não tolera perder o lugar enquanto digita o cartão. Comprando pelo celular, provavelmente em rede ruim.

**Organizador (Rafael, 41)** — publica de 1 a 5 eventos por ano. Precisa saber quanto vendeu, quanto falta e quem entrou. Não é técnico.

**Portaria (equipe do evento)** — valida centenas de ingressos por hora, na porta, com internet instável. Precisa de leitura rápida e resposta binária: entra ou não entra.

---

## 4. Modelo de domínio

### 4.1 Agregados

**`Evento`** — raiz. Contém dados de apresentação, data, local e status de publicação. Não guarda estoque.

**`Lote`** — raiz própria. É o **limite de consistência transacional** do estoque.

- `quantidadeTotal`
- `quantidadeReservada`
- `quantidadeVendida`
- `precoUnitario` (Value Object `Dinheiro`)
- `janelaDeVenda` (Value Object `Periodo`)

**`Reserva`** — raiz própria. Referencia `loteId` por identidade, nunca por objeto.

- `expiraEm`
- `quantidade`
- `status`
- `compradorEmail`

**`Ingresso`** — raiz própria. Criado apenas quando a reserva é confirmada.

- `codigo` (Value Object `CodigoIngresso`, único, não sequencial)
- `status`

### 4.2 Decisão de fronteira (a mais importante do projeto)

O invariante crítico é:

```
quantidadeReservada + quantidadeVendida <= quantidadeTotal
```

Ele atravessa `Lote` e `Reserva`. Duas formas de garantir:

| Opção                                                       | Como                                                                  | Veredito                                                                                                      |
| ----------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| A — `Lote` contém a coleção de `Reserva`                    | Invariante checado em memória dentro do agregado                      | **Rejeitada.** O agregado cresce sem limite. Carregar 5.000 reservas para reservar 1 ingresso é insustentável |
| B — `Lote` guarda contadores, `Reserva` é agregado separado | Contadores mantidos sob lock, invariante checado antes de incrementar | **Escolhida**                                                                                                 |

Consequência da opção B: o `Lote` é a única coisa que precisa ser travada. A `Reserva` pode ser gravada livremente. Isso vira o ADR-001.

### 4.3 Máquina de estados da Reserva

```
                  ┌─────────────┐
                  │  PENDENTE   │  (criada, estoque retido)
                  └──────┬──────┘
             ┌───────────┼───────────┐
             ▼           ▼           ▼
      ┌────────────┐ ┌────────┐ ┌───────────┐
      │ CONFIRMADA │ │EXPIRADA│ │ CANCELADA │
      └────────────┘ └────────┘ └───────────┘
        (pagamento    (prazo      (usuário
         aprovado)     estourou)   desistiu)
```

Os três estados finais são terminais. Nenhuma transição sai deles. Uma reserva expirada não é reaproveitada — cria-se outra.

### 4.4 Eventos de domínio

- `ReservaCriada` → dispara e-mail com o prazo
- `ReservaConfirmada` → emite os ingressos, envia por e-mail
- `ReservaExpirada` → libera contador do lote
- `LoteEsgotado` → notifica organizador
- `IngressoUtilizado` → registra entrada

Consumidos via Symfony Messenger. No MVP, transporte assíncrono para e-mails; síncrono para o resto.

---

## 5. Regras de negócio

Cada regra abaixo deve ter um teste de unidade correspondente no domínio, sem banco.

| ID    | Regra                                                                                                  |
| ----- | ------------------------------------------------------------------------------------------------------ |
| RN-01 | Uma reserva expira 10 minutos após a criação. O prazo é configurável por evento entre 5 e 30 minutos   |
| RN-02 | Uma reserva é **ativa** se `status = PENDENTE` **e** `expiraEm > agora`. Reservas ativas retêm estoque |
| RN-03 | Não é possível reservar quantidade maior que a disponibilidade do lote no instante da requisição       |
| RN-04 | Máximo de 6 ingressos por reserva                                                                      |
| RN-05 | Um mesmo e-mail não pode ter mais de 2 reservas ativas simultâneas no mesmo evento                     |
| RN-06 | Reserva só pode ser criada dentro da janela de venda do lote                                           |
| RN-07 | Só é possível confirmar reserva que esteja ativa. Confirmar reserva expirada retorna erro 409          |
| RN-08 | Ao confirmar, gera-se um `Ingresso` por unidade da reserva                                             |
| RN-09 | O código do ingresso é aleatório e não adivinhável. Nada de ID sequencial                              |
| RN-10 | Um ingresso só pode ser utilizado uma vez. Segunda leitura retorna recusa com o horário da primeira    |
| RN-11 | Um lote não pode ser publicado com quantidade menor que a já vendida                                   |
| RN-12 | Evento com qualquer venda confirmada não pode ser excluído, apenas cancelado                           |

### 5.1 Expiração preguiçosa (lazy expiration)

**Não existe job varrendo reservas vencidas.**

O estoque disponível é sempre calculado como:

```sql
disponivel = quantidade_total
           - quantidade_vendida
           - (SELECT COALESCE(SUM(quantidade), 0)
              FROM reserva
              WHERE lote_id = :id
                AND status = 'PENDENTE'
                AND expira_em > NOW())
```

Vantagens sobre o cron clássico:

- Não há janela de inconsistência entre o vencimento e a varredura
- Funciona em infraestrutura que escala a zero
- Menos peça móvel para quebrar

O contador `quantidade_reservada` na tabela existe apenas como cache desnormalizado para leitura rápida; a verdade é a query acima. Um job noturno opcional apenas marca `EXPIRADA` para higiene de dados e relatório — nunca para correção de estoque.

Isso vira o ADR-002.

---

## 6. Requisitos não funcionais

### 6.1 Concorrência (o requisito central)

Ao criar uma reserva, a transação deve:

1. Adquirir lock pessimista na linha do lote — `SELECT ... FOR UPDATE`
2. Recalcular a disponibilidade real dentro do lock
3. Rejeitar se insuficiente
4. Gravar a reserva e atualizar o contador
5. Liberar

**Critério de aceite:** existe um teste de integração que dispara N requisições concorrentes contra um lote com estoque 1 e prova que exatamente uma vence e N-1 recebem 409. Esse teste é o item mais importante do repositório inteiro.

Alternativa considerada: lock otimista com coluna `version` e retry. Rejeitada para o caminho de reserva porque, sob disputa alta no último ingresso, a taxa de retry explode. Mantida para edição de dados do evento, onde a disputa é baixa.

### 6.2 Idempotência

- `POST /reservas` aceita header `Idempotency-Key`. Chave repetida em até 24h retorna a reserva original em vez de criar outra
- O webhook do gateway é idempotente por `event_id` do provedor. Reprocessamento não duplica ingresso

Motivo: rede móvel derruba requisições. O usuário aperta o botão de novo. Sem isso, ele reserva dois lugares.

### 6.3 Segurança

- Autenticação por JWT com refresh token em cookie `httpOnly`
- Comprador não precisa de conta para reservar — apenas e-mail verificado por código
- Webhook valida assinatura HMAC do provedor; requisição sem assinatura válida é descartada antes de qualquer processamento
- Nenhum dado de cartão passa pela aplicação. Tokenização no lado do gateway
- Rate limit em `POST /reservas`: 10 por minuto por IP
- Log de auditoria imutável para transições de estado de reserva e ingresso
- Dados pessoais mínimos (nome, e-mail). Sem CPF no MVP — não é necessário e cria obrigação de LGPD desproporcional ao projeto

### 6.4 Performance

- Listagem de eventos: p95 abaixo de 300ms
- Criação de reserva: p95 abaixo de 500ms
- Validação de ingresso na portaria: p95 abaixo de 200ms
- A tela de disponibilidade pode ser eventualmente consistente por até 5 segundos. A criação da reserva, nunca

### 6.5 Observabilidade

- Logs estruturados em JSON com `correlation_id` atravessando frontend, API e worker
- Health check em `/health` verificando banco e fila
- Métrica de negócio exposta: taxa de conversão reserva → venda, e taxa de expiração

---

## 7. Arquitetura e stack

### 7.1 Componentes

Toda a aplicação roda em um único droplet DigitalOcean gerenciado por EasyPanel, com os serviços isolados em containers e comunicando por rede interna do Docker.

| Camada              | Tecnologia                                    | Hospedagem              |
| ------------------- | --------------------------------------------- | ----------------------- |
| Frontend            | Next.js (App Router), TypeScript, Tailwind    | EasyPanel               |
| API                 | PHP 8.3, Symfony 7, Doctrine ORM              | EasyPanel               |
| Worker              | Symfony Messenger, processo permanente        | EasyPanel               |
| Banco               | PostgreSQL 16                                 | EasyPanel, rede interna |
| Fila                | Symfony Messenger (Doctrine transport no MVP) | —                       |
| Registry de imagens | GHCR                                          | GitHub                  |
| CDN / DNS / WAF     | Cloudflare                                    | —                       |
| Pagamento           | Gateway em sandbox                            | —                       |

### 7.2 Camadas da API (DDD)

```
src/
├── Domain/           entidades, VOs, eventos, interfaces de repositório
│                     ZERO dependência de Symfony ou Doctrine
├── Application/      casos de uso (command handlers), DTOs
├── Infrastructure/   Doctrine, mapeamento XML, gateway, e-mail, fila
└── UI/               controllers HTTP, serialização, validação de entrada
```

Regra inegociável: se `Domain/` importar qualquer namespace `Doctrine\` ou `Symfony\`, a camada está errada. O mapeamento ORM fica em XML dentro de `Infrastructure/`, justamente para manter as entidades limpas. Um teste de arquitetura (Deptrac ou PHPArkitect) falha o CI se essa regra for violada.

### 7.3 Notas de infraestrutura

**Rede.** O Postgres não expõe porta pública em hipótese alguma. Ele é alcançável apenas pela rede interna do Docker, pelo hostname do serviço. O firewall do droplet aceita 80, 443 e SSH, e nada mais. Acesso administrativo ao banco se faz por túnel SSH.

**Pipeline de deploy.** As imagens são construídas no GitHub Actions e publicadas no GHCR. O EasyPanel apenas puxa a imagem pronta e sobe. O droplet nunca compila nada.

Motivos: o build do Next.js consome memória suficiente para derrubar droplets pequenos; o build no CI é reprodutível e versionado; e o rollback vira troca de tag de imagem, não rebuild.

O gatilho do deploy usa a API do EasyPanel, chamada pelo workflow após o push da imagem.

**Migrations.** Executadas como etapa explícita do deploy, antes do container novo receber tráfego. Nunca automaticamente no boot da aplicação — dois containers subindo ao mesmo tempo rodariam a mesma migration em paralelo.

**Backup.** Responsabilidade inteiramente própria a partir de agora:

- `pg_dump` diário, comprimido, enviado para DigitalOcean Spaces, retenção de 30 dias
- Snapshot semanal do droplet
- **Um restore de teste, feito de verdade, uma vez.** Backup nunca testado não é backup

**Recursos.** Postgres, PHP-FPM, worker e Next.js dividem a mesma máquina. Definir limites de memória por container no EasyPanel para que um build ou um pico não derrube o banco. O Postgres é o serviço que nunca pode ser sacrificado.

**Ambientes.** Um único ambiente de produção. Staging roda localmente via Docker Compose. Manter dois ambientes no mesmo droplet consome recurso e atenção sem contrapartida para um projeto deste porte.

### 7.4 Migrations

Toda mudança de esquema via Doctrine Migrations versionada no repositório. O painel do Supabase nunca é usado para alterar esquema — apenas para inspeção.

---

## 8. Modelo de dados (essencial)

```
evento         (id, organizador_id, titulo, descricao, local,
                inicia_em, status, criado_em)

lote           (id, evento_id, nome, preco_centavos, moeda,
                quantidade_total, quantidade_vendida,
                vendas_iniciam_em, vendas_terminam_em, version)

reserva        (id, lote_id, comprador_email, quantidade,
                status, expira_em, idempotency_key, criado_em)

ingresso       (id, reserva_id, codigo, status,
                utilizado_em, emitido_em)

pagamento      (id, reserva_id, provedor_id, valor_centavos,
                status, payload_bruto, recebido_em)

auditoria      (id, entidade, entidade_id, de, para,
                ator, ocorrido_em)
```

Decisões:

- Dinheiro sempre em **centavos, inteiro**. Nunca `float`, nunca `NUMERIC` com casas decimais no código PHP
- Índice parcial em `reserva (lote_id) WHERE status = 'PENDENTE'` — é a query mais quente do sistema
- `UNIQUE` em `ingresso.codigo` e em `reserva.idempotency_key`
- `UNIQUE` em `pagamento.provedor_id` — é o que garante idempotência do webhook no nível do banco, não só no código
- `CHECK (quantidade_vendida <= quantidade_total)` — a última linha de defesa. Se a aplicação falhar, o banco recusa

---

## 9. API (contrato resumido)

```
POST   /api/eventos                      cria evento (organizador)
POST   /api/eventos/{id}/publicar
GET    /api/eventos                      lista pública, paginada
GET    /api/eventos/{id}                 detalhe com lotes e disponibilidade

POST   /api/reservas                     cria reserva  [Idempotency-Key]
GET    /api/reservas/{id}                estado e segundos restantes
DELETE /api/reservas/{id}                cancela

POST   /api/reservas/{id}/checkout       inicia pagamento
POST   /api/webhooks/pagamento           callback do gateway  [HMAC]

GET    /api/ingressos/{codigo}           consulta
POST   /api/ingressos/{codigo}/utilizar  validação na portaria

GET    /api/organizador/eventos/{id}/painel
```

Erros seguem RFC 7807 (`application/problem+json`). O front distingue 409 "esgotou enquanto você decidia" de 409 "sua reserva expirou" pelo campo `type` — mensagens diferentes, tratamentos diferentes.

---

## 10. Requisitos de interface

### 10.1 Comprador

- Lista de eventos com estado de estoque visível antes do clique
- Seleção de lote e quantidade com disponibilidade atualizada
- **Contador regressivo persistente** durante o checkout, calculado a partir de `expiraEm` vindo do servidor, nunca de um timer local iniciado no navegador. Recarregar a página mantém o tempo correto
- Estado de expiração tratado com dignidade: quando o prazo acaba, a tela explica o que houve e oferece tentar de novo, sem despejar erro genérico
- Confirmação com ingresso exibido e enviado por e-mail

### 10.2 Organizador

- Criação de evento e lotes
- Painel com vendidos, reservados no momento, disponíveis e receita
- Lista de compradores exportável em CSV

### 10.3 Portaria

- Tela única de leitura de código
- Resposta visual grande e inequívoca: verde entra, vermelho não entra
- Recusa sempre acompanhada do motivo (já utilizado, inválido, evento errado)

---

## 11. Roadmap

| Fase | Entrega                                                  | Prova técnica                     |
| ---- | -------------------------------------------------------- | --------------------------------- |
| 0    | Docker Compose, esqueleto Symfony, CI                    | Ambiente reprodutível             |
| 1    | Domínio de `Lote` e `Reserva` com testes puros           | DDD real, sem banco nos testes    |
| 2    | Persistência, lock pessimista, **teste de concorrência** | O item mais importante do projeto |
| 3    | API completa e frontend do comprador                     | Next.js, TS, Tailwind, Axios      |
| 4    | Gateway, webhook, idempotência                           | Integração entre sistemas         |
| 5    | Painel do organizador e portaria                         | Cobertura do produto              |
| 6    | Deploy, observabilidade, ADRs, README                    | Apresentação                      |

Fase 2 antes de qualquer tela. É contraintuitivo e é o certo: se a concorrência não estiver resolvida, o resto é fachada.

---

## 12. Definição de pronto

Uma funcionalidade só está pronta quando:

- [ ] Regras de negócio têm teste de unidade no domínio, sem infraestrutura
- [ ] Caminho crítico tem teste de integração
- [ ] Erros retornam RFC 7807 com `type` acionável
- [ ] Não há dependência de framework em `Domain/`
- [ ] Migration versionada, aplicável do zero
- [ ] Decisão relevante registrada em ADR

---

## 13. Riscos

| Risco                                   | Impacto  | Mitigação                                                                                |
| --------------------------------------- | -------- | ---------------------------------------------------------------------------------------- |
| Perda de dados por falha do droplet     | **Alto** | `pg_dump` diário para Spaces, snapshot semanal, restore testado uma vez de verdade       |
| Droplet sem memória derruba o Postgres  | Alto     | Build no CI, limites de memória por container, Postgres com prioridade                   |
| Escopo crescer e o projeto não terminar | **Alto** | Seção 2.2 é contrato. Ideia nova vira issue com label `pos-mvp`                          |
| Postgres exposto à internet             | **Alto** | Rede interna do Docker apenas, firewall em 80/443/SSH, acesso admin por túnel            |
| Ponto único de falha no droplet         | Médio    | Aceito conscientemente. É portfólio, não sistema de missão crítica. Documentar a decisão |
| DDD virar pasta vazia com nome bonito   | Alto     | Teste de arquitetura no CI barrando importação cruzada                                   |

---

## 14. Como este projeto será avaliado

Quem abrir o repositório deve, em 90 segundos, conseguir:

1. Entender o que o sistema faz, pelo README com GIF e diagrama
2. Encontrar o teste de concorrência e entender o que ele prova
3. Ler um ADR e ver raciocínio, não jargão
4. Rodar tudo com um `docker compose up`

Se algum desses quatro falhar, o projeto não cumpriu o objetivo da seção 1.2 — independentemente da qualidade do código.
