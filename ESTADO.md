# Estado do projeto — retomada

Documento de handoff. O `PLAN.md` diz o que fazer e em que ordem; este diz
**onde paramos**, o que está no ar e o que morde.

**Última atualização:** julho de 2026, após publicar login e cadastro.

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
| Painel do organizador | ⬜ mock — falta `GET /api/organizador/eventos/{id}/painel` |
| Portaria | ⬜ mock — falta `POST /api/ingressos/{codigo}/utilizar` |
| Ingresso emitido | ⬜ mock — depende do gateway (fase 5) |

**Próximo passo recomendado:** o painel do organizador. É onde o vínculo do
ADR-004 aparece na tela — Rafael vê os eventos dele, e só os dele. O
`EventoVoter` já existe e está testado; falta o endpoint e ligar a tela.

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
