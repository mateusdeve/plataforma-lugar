# Estado do projeto — retomada

Documento de handoff. O `PLAN.md` diz o que fazer e em que ordem; este diz
**onde paramos**, o que está no ar e o que morde.

**Última atualização:** julho de 2026, após a fase 6 completa (criar/publicar
evento, escala de operadores) e a parte de código da fase 8 (logs JSON com
correlation id, README e ADRs revisados).

---

## No ar

| | |
| - | - |
| Front | **https://comprarbem.store** — Next.js na Vercel |
| API | **https://api.comprarbem.store** — Symfony no EasyPanel |
| Repositório | https://github.com/mateusdeve/plataforma-lugar |
| Imagem | `ghcr.io/mateusdeve/plataforma-lugar/api:latest` (pública) |

### Contas de demonstração

Criadas por `php bin/console lugar:popular`. Senha de todas: `demonstracao123`.

| perfil | e-mail |
| ------ | ------ |
| Organizador | `rafael@lugar.demo` |
| Portaria | `portaria@lugar.demo` — escalada **só** no FrontZ |
| Comprador | `ana@lugar.demo` |

A portaria estar escalada em um evento só é de propósito: é assim que se
demonstra o `PortariaVoter` recusando ingresso de outro evento.

---

## O que está integrado, o que ainda é mock

`apps/web/lib/dados.ts` é o **único** arquivo que sabe de onde os dados vêm.
O cabeçalho dele tem a lista atualizada. Hoje:

| tela | estado |
| ---- | ------ |
| Vitrine, detalhe do evento | ✅ API |
| Criar / consultar / cancelar reserva | ✅ API |
| Login, cadastro, sessão | ✅ API |
| Painel do organizador | ✅ API |
| Criar e publicar evento | ✅ API |
| Escala da portaria (no painel) | ✅ API |
| Checkout, pagamento, ingresso emitido | ✅ API |
| Portaria | ✅ API |

**Não há mais nenhum mock.** `lib/dados.ts` é inteiramente API.

Portões verdes: `composer check` sai 0 (100 testes), e o front passa em `tsc`,
`eslint` e `next build`. O fluxo foi percorrido no navegador de ponta a ponta,
inclusive criar → publicar → escalar → excluir evento.

### Fase 6 — o que existe (completa)

- `POST /api/eventos` (nasce RASCUNHO, com lotes, numa transação),
  `POST /api/eventos/{id}/publicar`, `POST /api/eventos/{id}/cancelar`,
  `DELETE /api/eventos/{id}`
- RN-12 no `ExcluirEvento`: contagem de vendas confirmadas e exclusão na
  MESMA transação; com venda, 409 `type=evento-com-vendas`
- RN-11 é invariante do próprio `Lote` (construtor + `redimensionarPara`);
  o que `PublicarEvento` acrescenta é recusar evento sem lote
  (`type=evento-sem-lote`)
- Escala: `GET/POST/DELETE /api/organizador/eventos/{id}/operadores`, atrás
  de `EVENTO_ESCALAR_PORTARIA` no `EventoVoter`. **Escalar concede
  `ROLE_PORTARIA`** — decisão registrada na revisão do ADR-004; retirar da
  escala não revoga o papel
- `GestaoDeEventosTest` e `EscalaDeOperadoresTest` — cada rota com caso
  negativo de token válido; a escala é provada pela CONSEQUÊNCIA (o porteiro
  escalado abre `GET /api/portaria/{eventoId}` de um evento e leva 403 no
  outro)
- CSV de compradores continua no cliente (`exportar-csv.tsx`), sobre os
  mesmos ≤200 do painel — decisão documentada no componente

### Fase 8 — o que já existe (a parte de código)

- **Logs JSON com `correlation_id` atravessando front → API → worker.**
  O front gera um id por requisição (`lib/api.ts`), a API o aceita/devolve em
  `X-Correlation-Id` (`Infrastructure/Observabilidade/`), o Monolog o carimba
  em todo registro, e ele viaja DENTRO da mensagem da fila até o log do
  e-mail. Verificado de ponta a ponta com uma compra simulada
- Em produção o handler `main` é stream JSON direto no stderr (SEM
  `fingers_crossed` — o raciocínio está comentado no `monolog.yaml`)
- Métricas de negócio do PRD §6.5 (conversão reserva→venda, taxa de
  expiração) já estavam no painel desde a 6.2
- README reescrito (tour de 90 segundos, PRD §14) e ADR-004 com revisão
  pós-construção

### Fase 7 — o que existe

- `GET /api/portaria/{eventoId}` (estado da porta), `GET /api/ingressos/{codigo}`
  (confere sem consumir) e `POST /api/ingressos/{codigo}/utilizar`
- `PortariaTest` — o critério de pronto: segunda leitura recusa **com o
  horário**, e ingresso de outro evento recusa com o motivo certo. Mais a
  escala: porteiro com `ROLE_PORTARIA` num evento onde não está escalado leva
  403
- **`CatracaConcorrenteTest`** — 8 processos lendo o MESMO código no mesmo
  instante; exatamente 1 entra. Verificado que ele FALHA sem o lock: sem
  `SELECT ... FOR UPDATE`, as 8 leituras passam e 8 pessoas entram com um
  ingresso
- O horário da recusa vai em **campo próprio** do problem+json (`utilizadoEm`),
  não dentro da frase. `detail` é para humanos lerem; a tela consome o campo

### O que mudou no front

A tela da portaria guardava os códigos já lidos numa ref do React e recusava a
segunda passada localmente. Funcionava com UMA catraca — e era exatamente o bug
que a RN-10 existe para impedir: com dois leitores, cada um tem a sua memória.
Agora nada é decidido no cliente.

### Fase 5 — o que existe

- `POST /api/reservas/{id}/checkout` abre a cobrança; `POST /api/webhooks/pagamento`
  recebe a confirmação
- `WebhookTest` — o critério de pronto: o mesmo webhook entregue **3 vezes**
  emite ingresso uma só vez. Mais assinatura ausente, segredo errado, corpo
  adulterado e replay fora da janela, cada um provando que nada é emitido
- **Não há Stripe.** `GatewayDePagamento` é uma porta; o adaptador que existe é
  o de demonstração, que roda sem chave nenhuma. Ligar o Stripe é escrever uma
  classe e trocar uma linha em `services.yaml` — nenhum caso de uso muda
- `POST /api/reservas/{id}/simular-pagamento` faz o papel do provedor em
  dev/test e **responde 404 em produção**. Ela monta o webhook assinado e o
  manda pela mesma verificação HMAC — o caminho exercitado localmente é o de
  produção
- E-mail por Messenger, despachado **dentro** da transação: com transporte
  `doctrine://` isso é outbox de graça (ver `NotificadorPorMensageria`)

### Desvios conscientes da fase 5

- **Sem eventos de domínio.** Os cinco previstos em 1.5 nunca existiram.
  `ConfirmarPagamento` orquestra tudo numa transação só — confirmar, vender
  estoque, emitir e notificar. Emitir fora da transação abriria janela de
  inconsistência
- **A tela do ingresso é por RESERVA** (`/reservas/{id}/ingressos`), não por
  código: RN-08 emite um ingresso por unidade, e uma tela por código esconderia
  os outros
- `GET /api/ingressos/{codigo}` continua sendo da fase 7, e não foi antecipado

**Não há mais buraco funcional.** O que resta da fase 8 é operação, e tudo
exige acesso que não está neste repositório:

1. ~~8.3 — backup diário~~ **FEITO**: `pg_dump` diário às 03h17 no droplet,
   versionado no repositório privado `mateusdeve/lugar-backups` via deploy
   key exclusiva (custo zero; Spaces fica como upgrade documentado em
   `ops/backup/`). Snapshot pago do droplet ficou de fora por decisão de
   custo — o raciocínio está no `ops/backup/README.md`.
2. ~~8.4 — restore de teste~~ **FEITO em 2026-07-28**: dump de produção
   restaurado no compose local, aplicação subiu em cima, `/health` ok.
   Registrado na tabela do `ops/backup/README.md`.
3. **Rotação dos tokens** da seção de pendências de segurança abaixo.
4. Opcional: religar o deploy automático (`DEPLOY_ATIVO` + webhook novo do
   EasyPanel — atenção: gerar o webhook invalida o token anterior).

---

## Infraestrutura

### Droplet — compartilhado com produção de terceiros

`104.248.62.109`, 2 GB, roda **também** o `evolution-api` do negócio do dono.
Isso não é detalhe: o OOM killer do Linux não mata quem causou o problema,
mata quem está consumindo mais.

Proteções em vigor:

- **O droplet nunca compila.** O build acontece no GitHub Actions; ele só
  puxa imagem pronta (ADR-003)
- **Limite rígido de 256 MB** em `lugar-api` e `lugar-db`. Se algo nosso
  vazar memória, bate no próprio teto e morre sozinho
- Projeto `lugar` isolado do `movitre`, com Postgres próprio

Medido: a imagem de produção usa ~79 MB dos 256 MB.

**Nunca use `memoryReservation`.** Reserva no Swarm bloqueia o recurso mesmo
sem uso, e foi o que impediu o container de agendar na primeira tentativa
("insufficient resources"). O que protege é o *limite*, não a reserva.

### DNS (Cloudflare)

| nome | tipo | destino | proxy |
| ---- | ---- | ------- | ----- |
| `comprarbem.store` | A | `76.76.21.21` (Vercel) | **cinza** |
| `www` | CNAME | `cname.vercel-dns.com` | cinza |
| `api` | A | `104.248.62.109` | **laranja** |

O front fica cinza de propósito: Cloudflare na frente da Vercel é CDN sobre
CDN. O `api` fica laranja, que é onde o WAF tem função.

### EasyPanel

A API tem OpenAPI documentado em `https://easy.movitre.com.br/api` — 374
rotas. O prefixo das chamadas é **`/api/rpc`**, e o corpo vai sempre
embrulhado em `{"json": {...}}`.

O deploy automático **está desligado**: a variável de repositório
`DEPLOY_ATIVO` é `false` e o secret `EASYPANEL_DEPLOY_WEBHOOK` não foi
configurado. Para ligar, pegue o webhook em
`/services/app/refreshDeployToken` (atenção: isso invalida o token anterior)
e defina os dois no GitHub.

Enquanto está desligado, o deploy é manual:
`POST /api/rpc/services/app/deployService`.

---

## Armadilhas já encontradas — não repetir

**Verifique exit code, não a saída.** Passei dois commits reportando "verde"
porque filtrava a saída do `composer check` com `grep` e lia "Violations 0",
enquanto o exit code era 1 e o CI estava vermelho.

**O Deptrac pega violação real, quatro vezes até agora.** Health check,
`AuthController`, `MigrarCommand` e a camada de terceiros. Quando ele
reclamar, a resposta é corrigir o desenho — não afrouxar a regra.

**`.dockerignore` é obrigatório.** Sem ele, o `COPY . .` levava o `vendor/`
da máquina local para dentro da imagem, sobrescrevendo o que o composer
tinha instalado — a imagem subia sem o SecurityBundle e quebrava com um erro
sem relação com a causa. E pior: qualquer arquivo local iria para um registry
público.

**Os recipes do Flex duplicam entradas no `.env`.** Havia dois `APP_SECRET`
e dois `DATABASE_URL`; a última declaração vence, então um `APP_SECRET` vazio
anulava o real em silêncio. Ao instalar pacote novo, confira o `.env`.

**A Vercel liga SSO Protection por padrão.** O site devolvia 302 para login.
Fácil de não notar: logado você vê 200, e qualquer outra pessoa leva redirect.
Desligado via `PATCH /v9/projects/{id}` com `ssoProtection: null`.

**Alias de domínio na Vercel demora a propagar.** Depois de
`vercel alias set`, rotas novas podem dar 404 por alguns minutos. Teste a URL
do deploy direto antes de concluir que o build quebrou.

**`buscarParaAtualizacao()` desanexa TODAS as entidades.** Ele chama
`EntityManager::clear()` antes do `SELECT ... FOR UPDATE`, e tem de chamar —
senão o Doctrine devolve a cópia do Identity Map e não trava nada. O efeito
colateral é que qualquer entidade carregada ANTES vira detached, e `salvar()`
nela agenda um INSERT em vez de um UPDATE. O sintoma é
`duplicate key violates ..._pkey`, três camadas longe da causa. Custou meia
hora na fase 5. **Regra: trave primeiro, carregue o resto depois.**

**Teste roda em `lugar_test`, não em `lugar`.** `doctrine.yaml` tem
`dbname_suffix: _test` em `when@test`. Migration nova precisa ser aplicada nos
dois bancos, ou a suíte quebra com "column does not exist" enquanto o banco de
desenvolvimento está correto:
`docker compose exec -e APP_ENV=test api php bin/console doctrine:migrations:migrate`

**Percorra o fluxo no navegador antes de dar por pronto.** O checkout estava
quebrado desde que a reserva foi ligada: `lib/tipos.ts` declarava `eventoId` na
reserva e a API nunca mandava, então a tela chamava `buscarEvento(undefined)` e
caía em 404. Nenhum teste pegou — nenhum deles atravessa o front. Contrato
declarado e contrato entregue são coisas diferentes.

**E percorra COMEÇANDO do clique, não da URL.** A segunda vez que a armadilha
acima mordeu foi pior: o botão "Guardar meu lugar" ainda navegava para
`/checkout/res-demo` — o mock da fase de design sobrevivendo DENTRO do
`SeletorDeLotes` enquanto `lib/dados.ts` tinha `criarReserva()` pronta e
testada. Todos os testes de checkout partiam de uma reserva criada por curl,
então o único caminho que o usuário real usa era o único nunca exercitado. Só
apareceu ao gravar o GIF do fluxo em produção.

**Dado de demonstração precisa ser um estado possível.** O `lugar:popular`
gravava `quantidade_vendida` no lote sem criar reserva nenhuma — ingresso
vendido sem ninguém ter comprado, coisa que a operação real nunca produz. A
vitrine não notava; o painel do organizador nasceu mostrando 436 vendidos e
R$ 0,00 de receita. Não era bug do painel. Semente que não poderia existir em
produção faz tela correta parecer quebrada, e esconde o inverso.

**Se o Docker Desktop travar, o resíduo fica no volume.** O backend trava
(aceita conexão no socket, `/backend/state` nunca responde) e matar a VM no
meio do shutdown deixa um `postmaster.pid` vazio — o Postgres recusa subir com
`bogus data in lock file`. Receita: matar `com.docker.backend` com `-9`, subir
o Docker, remover o `postmaster.pid` do volume e `docker compose up -d
--force-recreate`. Sem o `--force-recreate`, os containers antigos continuam
morrendo sem escrever log nenhum.

**Migrations rodam no boot, sob `pg_advisory_lock`** — ver
`src/Infrastructure/Console/MigrarCommand.php`. Não tente movê-las para o
pipeline sem ler o comentário lá.

---

## Pendências de segurança (do dono do projeto)

Ficaram expostas no histórico de uma conversa e **precisam ser rotacionadas**:

- Token de API do EasyPanel
- Token de DNS da Cloudflare (escopo: `comprarbem.store`)
- `SUPABASE_SERVICE_ROLE_KEY` e `OPENROUTER_API_KEY` do serviço `ai` no
  projeto `movitre` — a primeira ignora row-level security

---

## Como rodar tudo local

```bash
docker compose up -d
docker compose exec api php bin/console lugar:popular
cd apps/web && npm install && npm run dev
```

Os três portões, sempre pelo exit code:

```bash
docker compose exec api composer check
```
