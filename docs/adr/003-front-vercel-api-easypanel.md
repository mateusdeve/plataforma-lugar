# ADR-003 — Front na Vercel, API no EasyPanel, sob o mesmo domínio

**Status:** aceito
**Data:** julho de 2026
**Substitui:** PRD §7.1, que colocava o Next.js no EasyPanel

## Contexto

O PRD previa tudo em um único droplet. A decisão mudou: o front vai para a Vercel, a API e o Postgres ficam no EasyPanel.

O ganho é concreto. O build do Next.js consome memória suficiente para derrubar um droplet pequeno — e o droplet é o mesmo lugar onde mora o Postgres, o serviço que não pode ser sacrificado. Tirar o front de lá remove o vizinho mais barulhento de perto do recurso mais crítico, e ainda entrega preview por pull request de graça.

O custo é que front e API deixam de ser o mesmo host. Isso não é detalhe de configuração: é o que decide como a autenticação funciona.

## O problema real

Autenticação usa refresh token em cookie `httpOnly` — o navegador precisa enviá-lo automaticamente nas chamadas à API. Se front e API estiverem em sites diferentes, o cookie precisa de `SameSite=None`, que exige `Secure` e depende de o navegador aceitar cookie de terceiros. Essa aceitação vem sendo retirada. Construir autenticação sobre ela é construir sobre algo que está sendo desligado.

## Opções

**A — hosts sem parentesco** (`comprarbem.vercel.app` + host do EasyPanel). Zero custo inicial, e um caminho de auth que quebra sozinho conforme os navegadores apertam cookie de terceiros. Rejeitada.

**B — BFF: o Next faz proxy de tudo.** O cookie fica *first-party* na Vercel e o navegador nunca fala com o Symfony. Resolve o problema de forma robusta. O custo é que cada endpoint passa a existir duas vezes — no Symfony e no route handler que o espelha — e a API real fica escondida atrás de uma camada de repasse. Num projeto cujo objetivo declarado é demonstrar competência em Symfony, esconder o Symfony é caro. Rejeitada, com ressalva: é o plano B se a opção C falhar.

**C — mesmo domínio registrável.** `comprarbem.store` na Vercel, `api.comprarbem.store` no EasyPanel. Subdomínios do mesmo domínio são *same-site*, ainda que sejam origens diferentes. O cookie funciona com `SameSite=Lax`, sem depender de cookie de terceiros. CORS resolve a diferença de origem.

## Decisão

**Opção C**, no domínio `comprarbem.store`:

| Nome | Tipo | Destino | Proxy Cloudflare |
| ---- | ---- | ------- | ---------------- |
| `comprarbem.store` | CNAME (flattening) | `cname.vercel-dns.com` | DNS only |
| `www` | CNAME | `cname.vercel-dns.com` | DNS only |
| `api` | A | IP do droplet | Proxied |

O front fica cinza de propósito: Cloudflare na frente da Vercel é CDN sobre CDN, com duas camadas de cache e emissão de certificado disputando entre si. A Vercel já é a borda. O `api` fica laranja, que é onde o WAF do PRD §7.1 tem função.

- Cookie de refresh: `httpOnly`, `Secure`, `SameSite=Lax`, **sem atributo `Domain`**
- CORS com `Allow-Credentials` e origem explícita — nunca `*`, que é incompatível com credenciais
- Access token de vida curta em memória no cliente; só o refresh vive em cookie

### Por que o cookie não leva `Domain`

A versão anterior desta decisão dizia `Domain=.comprarbem.store`. Está errado, e o erro só apareceu ao olhar o DNS real: o domínio hospedava uma loja Shopify. Um cookie com `Domain` no ápice é enviado a **todo** subdomínio e ao próprio ápice — o refresh token iria junto de cada requisição à loja, para servidores de terceiros, sem que nada no código sugerisse isso.

Sem o atributo, o cookie é *host-only*: nasce em `api.comprarbem.store` e só volta para lá. E continua funcionando, porque quem decide se o cookie é enviado é o destino da requisição, não a origem — o front em `comprarbem.store` chamando `api.comprarbem.store` é *same-site*, e `SameSite=Lax` deixa passar.

A loja foi removida, mas a regra fica: **escopo de cookie é o menor que funciona.** `Domain` no ápice é um alcance que ninguém pediu e que só pode causar dano.

## Consequências

**Isto torna o domínio próprio uma dependência de infraestrutura, não uma questão de marca.** Sem ele, a opção C não existe e o projeto cai na B.

Herdamos um domínio com histórico: `comprarbem.store` servia uma loja Shopify, removida antes deste projeto começar. Os registros do apex e do `www` ficaram órfãos apontando para o edge do Shopify, que responde 403. Eles são substituídos, não reaproveitados.

A fase 0 sobe um endpoint vazio em produção e prova que o front consegue chamá-lo com credenciais, antes de qualquer funcionalidade existir. Toda a autenticação depende dessa suposição estar certa, e o momento barato de descobrir que ela está errada é o primeiro dia.

O ambiente local não reproduz essa topologia — em `docker compose` tudo é `localhost` e a questão de *same-site* não aparece. Consequência prática: **CORS e cookie não podem ser validados localmente.** Só em produção, e por isso desde a fase 0.
