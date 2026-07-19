# PLAN — lugar.

**Status:** aprovado para execução
**Base:** `PRD.md` v1.0
**Última atualização:** julho de 2026

Este documento é o plano de execução. O PRD diz *o que* e *por quê*; este diz *em que ordem* e *com qual critério de pronto*. Onde os dois divergirem, este documento vence e o ADR correspondente explica a mudança.

---

## 1. Decisões travadas

Quatro decisões foram tomadas antes do início e não voltam à mesa sem ADR novo.

| # | Decisão | ADR |
| - | ------- | --- |
| 1 | Front na Vercel, API e Postgres no EasyPanel, sob o mesmo domínio registrável | [ADR-003](docs/adr/003-front-vercel-api-easypanel.md) |
| 2 | Comprador tem conta, **e** o checkout de convidado é mantido | [ADR-004](docs/adr/004-identidade-e-acesso.md) |
| 3 | Papéis são acumuláveis — um usuário pode ser organizador e portaria ao mesmo tempo | [ADR-004](docs/adr/004-identidade-e-acesso.md) |
| 4 | Monorepo `apps/web` + `apps/api` | seção 3 deste documento |

### Domínio — resolvido

**`comprarbem.store`**, já na Cloudflare. Front no apex, API em `api.` — a tabela de registros está no [ADR-003](docs/adr/003-front-vercel-api-easypanel.md).

O domínio hospedava uma loja Shopify, removida. Os registros do apex e do `www` continuam apontando para o Shopify e respondem 403; são substituídos na tarefa 0.6.

Isso destrava as tarefas 0.6 a 0.8, que agora dependem apenas de existir origem: o IP do droplet e o projeto na Vercel.

---

## 2. Correções ao PRD

Três incoerências do PRD v1.0 ficam resolvidas aqui.

**Supabase (PRD §7.4).** Resíduo de uma versão anterior. Não há Supabase no projeto: o Postgres 16 roda em container no EasyPanel, sem porta pública, e o acesso administrativo é por túnel SSH. A frase do PRD deve ser lida como "nenhum painel gráfico altera esquema — só Doctrine Migrations versionadas".

**`quantidade_reservada` (PRD §4.1 vs §8).** O campo existe no agregado mas não na tabela. **Some dos dois.** O ADR-002 já estabelece que a verdade do estoque é a query de disponibilidade; manter um contador desnormalizado ao lado dela cria uma segunda verdade que só pode dessincronizar. O `Lote` guarda `quantidadeTotal` e `quantidadeVendida`; reservado é sempre calculado. Se um dia a leitura doer, aí sim se discute cache — com medição na mão.

**RN-05 (máx. 2 reservas ativas por e-mail no mesmo evento).** Atravessa a fronteira do ADR-001: `Reserva` aponta para `Lote`, e `Evento` está acima disso. **Não é invariante de agregado.** Vira uma verificação na camada de aplicação, executada dentro da mesma transação da reserva, antes de adquirir o lock do lote — uma query que percorre `reserva → lote → evento`. Não recebe constraint de banco: a condição depende de tempo (`expira_em > NOW()`) e nenhum `UNIQUE` expressa isso. É a regra mais fraca do conjunto e o teste precisa deixar essa fraqueza explícita.

---

## 2.1 Estado atual — desvio de ordem consciente

O front foi construído antes das fases 0 a 3, fora da ordem prevista. A decisão
foi deliberada: o pacote de design é hi-fi e final, converter é trabalho
mecânico, e ter a casca pronta encurta as fases 4, 6 e 7.

O risco é o front virar fachada. As três defesas contra isso:

- **Tipos vêm do contrato, não da tela.** `apps/web/lib/tipos.ts` deriva do PRD
  §8 e §9. Nenhum campo existe para servir a um layout.
- **Uma única costura com os dados.** `apps/web/lib/dados.ts` é o único arquivo
  que sabe de onde vêm os dados. Cada função já é assíncrona e mapeada para o
  endpoint que vai substituí-la.
- **As regras já estão onde vão ficar.** Contador derivado de `expiraEm` do
  servidor, os dois 409 separados por `type`, teto do stepper por RN-03 e RN-04,
  RN-10 na portaria.

O que **não** existe e não deve ser confundido com pronto: nenhuma chamada de
rede, nenhuma autenticação, nenhuma persistência. Login e cadastro continuam
sendo trabalho da fase 3 — o design não os cobre e eles precisam ser desenhados
a partir dos tokens.

Telas prontas: as 11 do handoff, em 9 rotas (o handoff separa em arquivos alguns
estados que aqui são estado de uma rota só). `/guia` lista todas.

---

## 3. Estrutura do repositório

```
ingressos/
├── apps/
│   ├── api/                    Symfony 7 · PHP 8.3
│   │   ├── src/
│   │   │   ├── Domain/         zero Symfony, zero Doctrine
│   │   │   ├── Application/    casos de uso, DTOs
│   │   │   ├── Infrastructure/ Doctrine, mapeamento XML, gateway, e-mail
│   │   │   └── UI/             controllers, serialização, validação
│   │   ├── config/, migrations/, tests/
│   │   └── Dockerfile
│   └── web/                    Next.js App Router · TS · Tailwind
│       ├── app/, components/, lib/
│       └── (Root Directory da Vercel aponta aqui)
├── design/                     referências hi-fi — não é código de produção
├── docs/adr/
├── docker-compose.yml          ambiente local completo
├── PRD.md
└── PLAN.md
```

A Vercel constrói apenas `apps/web`. O GitHub Actions constrói apenas `apps/api` e publica no GHCR. Os dois pipelines ignoram um ao outro.

---

## 4. Modelo de acesso

### Papéis

`ROLE_COMPRADOR` · `ROLE_ORGANIZADOR` · `ROLE_PORTARIA` — acumuláveis, guardados em `usuario.papeis` (array).

### Tabelas novas

```
usuario          (id, email UNIQUE, senha_hash, nome, papeis[], criado_em)
evento_operador  (evento_id, usuario_id, criado_em)   PK composta
```

`evento_operador` é a escala da portaria: quem pode validar ingresso em qual evento.

### Matriz

| Ação | Comprador | Organizador | Portaria |
| ---- | --------- | ----------- | -------- |
| Listar e ver eventos publicados | ✅ | ✅ | ✅ |
| Criar reserva, pagar | ✅ | ✅ | ✅ |
| Ver as próprias reservas e ingressos | ✅ | — | — |
| Criar e publicar evento | ❌ | ✅ | ❌ |
| Painel e CSV | ❌ | ✅ *só dos próprios eventos* | ❌ |
| Escalar operador de portaria | ❌ | ✅ *só dos próprios eventos* | ❌ |
| Validar ingresso | ❌ | ✅ *só dos próprios eventos* | ✅ *só onde escalado* |

As três células em itálico são o ponto do sistema: **papel não basta, é preciso vínculo**. Isso se implementa com Symfony Security Voters, nunca com `if` espalhado em controller.

### Onde a permissão é decidida

No Symfony. Sempre. O `middleware.ts` do Next existe para não mostrar tela vazia a quem não pode entrar — é conveniência de UX, não segurança. Todo endpoint protegido precisa de teste que prove que responde 403 mesmo quando chamado direto, sem passar pelo front.

---

## 5. Fases

Cada fase termina com merge na `main` e deploy. Nenhuma fase acumula trabalho não publicado.

---

### Fase 0 — Fundação e caminho até produção

**Objetivo:** existir em produção antes de existir funcionalidade.

- 0.1 Monorepo, `.editorconfig`, `.gitignore`, licença
- 0.2 `docker-compose.yml`: postgres 16, php-fpm, nginx, worker, next em dev
- 0.3 Esqueleto Symfony 7 com as quatro camadas e um `/health` que verifica banco e fila
- 0.4 Esqueleto Next.js com Tailwind e os design tokens do `design/README.md` mapeados no `tailwind.config`
- 0.5 CI: PHPUnit, PHPStan nível máximo, Deptrac, ESLint, `tsc --noEmit`
- 0.6 **Domínio + DNS** — apex/`app` na Vercel, `api.` no EasyPanel via Cloudflare
- 0.7 CD: GitHub Actions constrói imagem da API, publica no GHCR, dispara o EasyPanel pela API; migrations como etapa explícita antes do container novo receber tráfego
- 0.8 Vercel conectada ao repo com Root Directory `apps/web`

**Pronto quando:** `docker compose up` sobe tudo local, `api.comprarbem.store/health` responde 200 em produção, o front em produção consegue chamar esse endpoint e a resposta passa pelo CORS.

**Por que primeiro:** deploy é onde projeto de portfólio morre. Descobrir na fase 8 que o cookie não atravessa custa o projeto; descobrir agora custa uma tarde.

---

### Fase 1 — Domínio puro

**Objetivo:** as regras de negócio existirem e serem testáveis sem banco, sem framework, sem I/O.

- 1.1 Value Objects: `Dinheiro` (centavos, inteiro), `Periodo`, `CodigoIngresso` (charset sem ambíguos, formato `LGR-XXXX-XXXX`)
- 1.2 `Lote` — `quantidadeTotal`, `quantidadeVendida`, `precoUnitario`, `janelaDeVenda`
- 1.3 `Reserva` — máquina de estados PENDENTE → CONFIRMADA | EXPIRADA | CANCELADA, terminais
- 1.4 `Ingresso`, `Evento`
- 1.5 Eventos de domínio: `ReservaCriada`, `ReservaConfirmada`, `ReservaExpirada`, `LoteEsgotado`, `IngressoUtilizado`
- 1.6 Interfaces de repositório, declaradas em `Domain/`
- 1.7 **Um teste de unidade por regra RN-01 a RN-12**, sem infraestrutura
- 1.8 Deptrac barrando `Doctrine\` e `Symfony\` dentro de `Domain/` — falha o CI

**Pronto quando:** a suíte de domínio roda em menos de um segundo e o Deptrac quebra o build se alguém importar framework em `Domain/`.

**Nota sobre RN-05:** não é testada aqui. É regra de aplicação (seção 2), testada na fase 2.

---

### Fase 2 — Persistência e concorrência

**A fase mais importante do projeto.** Nenhuma tela até ela terminar.

- 2.1 Mapeamento Doctrine em XML dentro de `Infrastructure/` — entidades permanecem limpas
- 2.2 Migration inicial: `evento`, `lote`, `reserva`, `ingresso`, `pagamento`, `auditoria`
- 2.3 Constraints que são a última linha de defesa: `CHECK (quantidade_vendida <= quantidade_total)`, `UNIQUE` em `ingresso.codigo`, `reserva.idempotency_key`, `pagamento.provedor_id`
- 2.4 Índice parcial `reserva (lote_id) WHERE status = 'PENDENTE'` — a query mais quente do sistema
- 2.5 Query de disponibilidade conforme ADR-002
- 2.6 `CriarReserva`: transação com `SELECT … FOR UPDATE` no lote, recálculo dentro do lock, rejeição, gravação
- 2.7 RN-05 verificada dentro da transação, antes do lock
- 2.8 **Teste de concorrência:** N processos paralelos contra um lote com estoque 1. Exatamente um vence; N−1 recebem recusa. Roda no CI, contra Postgres real, não sqlite
- 2.9 Log de auditoria imutável nas transições de estado

**Pronto quando:** o teste 2.8 passa de forma determinística em 20 execuções seguidas e um leitor do repositório consegue abrir esse arquivo e entender em um minuto o que ele prova.

---

### Fase 3 — Identidade e acesso

- 3.1 Migration `usuario` e `evento_operador`
- 3.2 Cadastro com senha (Argon2id) e validação de e-mail
- 3.3 Login → access token JWT curto (15 min) + refresh token em cookie `httpOnly`, `Secure`, `SameSite=Lax`, sem atributo `Domain` (ver ADR-003)
- 3.4 Rotação de refresh token e revogação no logout
- 3.5 `GET /api/me`
- 3.6 Voters: `EventoVoter` (posse), `PortariaVoter` (escala via `evento_operador`)
- 3.7 Rate limit no login: 5 tentativas por minuto por IP e por e-mail
- 3.8 Front: telas de login e cadastro no visual do `design/` (que não as cobre — desenhar seguindo os tokens), `middleware.ts` protegendo `/painel` e `/portaria`
- 3.9 **Testes de autorização:** para cada endpoint protegido, um caso provando 403 para papel errado e um provando 403 para papel certo sem vínculo

**Pronto quando:** existe um teste que tenta ler o painel de um evento alheio com um token de organizador válido e recebe 403.

**Nota:** 3.9 é o segundo teste mais importante do repositório, depois do 2.8.

---

### Fase 4 — Comprador

Telas: `design/comprador/01`, `02`, `03`, `04`.

- 4.1 `GET /api/eventos` paginado, `GET /api/eventos/{id}` com lotes e disponibilidade
- 4.2 `POST /api/reservas` com `Idempotency-Key` — chave repetida em 24h devolve a reserva original
- 4.3 `GET /api/reservas/{id}` com segundos restantes calculados no servidor
- 4.4 `DELETE /api/reservas/{id}`
- 4.5 Rate limit: 10 reservas por minuto por IP
- 4.6 Erros em RFC 7807 com `type` acionável
- 4.7 Vitrine e detalhe do evento
- 4.8 Checkout com **countdown derivado de `expiraEm` do servidor** — recarregar a página mantém o tempo; estados normal → âmbar <2min → vermelho pulsante <30s
- 4.9 Os **dois 409 distintos**: `estoque-insuficiente` vira banner na tela do evento; `reserva-expirada` vira a tela 06
- 4.10 Checkout de convidado e vínculo à conta quando logado

**Pronto quando:** dá para reservar, recarregar a página, ver o tempo correto, deixar expirar e cair numa tela que explica o que houve — sem erro genérico em nenhum ponto.

---

### Fase 5 — Pagamento e emissão

Telas: `design/comprador/05`, `06`.

- 5.1 Gateway em sandbox — **Stripe em test mode** (webhook assinado bem documentado e chaves de teste públicas facilitam o `docker compose up` de quem clonar)
- 5.2 `POST /api/reservas/{id}/checkout`
- 5.3 `POST /api/webhooks/pagamento` com validação HMAC **antes de qualquer processamento**; requisição sem assinatura válida é descartada
- 5.4 Idempotência do webhook garantida por `UNIQUE (pagamento.provedor_id)` — no banco, não só no código
- 5.5 `ReservaConfirmada` → emite um `Ingresso` por unidade (RN-08)
- 5.6 E-mail por Symfony Messenger, transporte assíncrono
- 5.7 Telas de confirmação e expiração; QR com lib real apontando para o código único

**Pronto quando:** reprocessar o mesmo webhook três vezes não duplica ingresso, e existe teste provando isso.

---

### Fase 6 — Organizador

Telas: `design/organizador/01`, `02`.

- 6.1 `POST /api/eventos`, `POST /api/eventos/{id}/publicar` — RN-11 e RN-12
- 6.2 `GET /api/organizador/eventos/{id}/painel`: vendidos, reservados agora, disponíveis, receita, conversão
- 6.3 Lista de compradores + exportação CSV
- 6.4 Escala de operadores de portaria
- 6.5 Front do painel e do formulário de novo evento com lotes
- 6.6 Toda rota passa pelo `EventoVoter`

**Pronto quando:** organizador A não consegue ver, editar nem exportar nada do organizador B — provado por teste, não por inspeção.

---

### Fase 7 — Portaria

Telas: `design/portaria/01`, `02`, `03`.

- 7.1 `GET /api/ingressos/{codigo}`, `POST /api/ingressos/{codigo}/utilizar`
- 7.2 RN-10: segunda leitura recusa e informa o horário da primeira
- 7.3 Recusa por evento errado — o ingresso é válido, mas não é desta porta
- 7.4 `PortariaVoter` restringindo ao evento escalado
- 7.5 Tela única: Enter valida, input força maiúsculas, resposta em tela cheia verde/vermelha, auto-dismiss em 2,8s, contador de entradas
- 7.6 p95 abaixo de 200ms — é a tela usada sob pressão, na porta, com internet ruim

**Pronto quando:** ler o mesmo código duas vezes dá verde e depois vermelho com horário, e ler um código de outro evento dá vermelho com o motivo certo.

---

### Fase 8 — Apresentação

O trabalho que decide se as sete fases anteriores contam.

- 8.1 Logs JSON com `correlation_id` atravessando front, API e worker
- 8.2 Métricas de negócio: conversão reserva → venda, taxa de expiração
- 8.3 `pg_dump` diário comprimido para DigitalOcean Spaces, retenção de 30 dias; snapshot semanal do droplet
- 8.4 **Um restore de teste, feito de verdade, uma vez.** Backup nunca testado não é backup
- 8.5 Limites de memória por container; Postgres com prioridade e nunca sacrificado
- 8.6 ADRs 001 a 004 revisados contra o que de fato foi construído
- 8.7 README com GIF do fluxo, diagrama e link direto para o teste de concorrência
- 8.8 Verificação da PRD §14: alguém que nunca viu o repositório consegue, em 90 segundos, entender o sistema, achar o teste de concorrência, ler um ADR e rodar tudo

---

## 6. Definição de pronto

Vale para toda funcionalidade, em qualquer fase:

- [ ] Regra de negócio com teste de unidade no domínio, sem infraestrutura
- [ ] Caminho crítico com teste de integração
- [ ] Endpoint protegido com teste de autorização negativa
- [ ] Erros em RFC 7807 com `type` acionável
- [ ] Nenhuma dependência de framework em `Domain/`
- [ ] Migration versionada, aplicável do zero
- [ ] Decisão relevante registrada em ADR

## 7. Controle de escopo

A seção 2.2 do PRD é contrato. Ideia nova durante a execução vira issue com label `pos-mvp` e não entra. O risco mais provável deste projeto não é técnico — é não terminar.
