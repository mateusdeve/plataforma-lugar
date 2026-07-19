# lugar.

Plataforma de venda de ingressos com **reserva temporária**: o comprador garante o lugar por 10 minutos enquanto paga. Se pagar, vira venda. Se não, o ingresso volta para o estoque.

> **Estado:** em construção. Hoje existe o front completo e as decisões de arquitetura documentadas. A API Symfony ainda não foi escrita — ver [o que falta](#estado-atual).

## O problema

Venda de ingresso é um problema de **estoque sob concorrência**. Dois usuários clicam em "comprar" no último ingresso no mesmo milissegundo. Sem tratamento, o banco aceita os dois e o evento tem 501 pessoas para 500 lugares.

A reserva temporária resolve isso de forma humana — e transforma o problema em um exercício de controle de concorrência, que é o que este repositório existe para demonstrar.

## Decisões que carregam o projeto

| ADR | Decisão | Por quê em uma linha |
| --- | ------- | -------------------- |
| [001](docs/adr/001-fronteira-do-agregado.md) | `Lote` guarda contadores; `Reserva` é agregado separado | Um agregado que cresce com o sucesso do evento é insustentável |
| [002](docs/adr/002-expiracao-preguicosa.md) | Expiração preguiçosa, sem cron | Um job de varredura cria uma janela em que o estoque existe mas o sistema não sabe |
| [003](docs/adr/003-front-vercel-api-easypanel.md) | Front na Vercel, API no EasyPanel, mesmo domínio | *Same-site* é o que permite cookie `httpOnly` sem depender de cookie de terceiros |
| [004](docs/adr/004-identidade-e-acesso.md) | Autorização por vínculo, não por papel | `ROLE_ORGANIZADOR` diz que pode organizar — não diz *quais* eventos |

Documentos completos: [PRD](PRD.md) (o quê e por quê) e [PLAN](PLAN.md) (em que ordem e com qual critério de pronto).

## Stack

| Camada | Tecnologia |
| ------ | ---------- |
| Front | Next.js (App Router), TypeScript, Tailwind — na Vercel |
| API | PHP 8.4, Symfony 8.1, Doctrine ORM 3 — no EasyPanel |
| Banco | PostgreSQL 16, sem porta pública |
| Fila | Symfony Messenger |

O `Domain/` da API não importa `Symfony\` nem `Doctrine\`, e um teste de arquitetura quebra o CI se alguém tentar.

## Rodando

Tudo de uma vez — banco, API e worker:

```bash
docker compose up -d
curl http://localhost:8000/health
```

O front, separado:

```bash
cd apps/web && npm install && npm run dev
```

`http://localhost:3000/guia` lista todas as telas com a origem no design e o endpoint que cada uma vai consumir.

### Os três portões de qualidade

```bash
docker compose exec api composer check
```

Roda, nesta ordem: **camadas** (Deptrac), **tipos** (PHPStan nível 9) e **testes** (PHPUnit).

### O teste que importa

```bash
docker compose exec api vendor/bin/phpunit --testsuite integracao
```

`tests/Integracao/Reserva/ConcorrenciaTest.php` dispara 10 processos PHP
independentes, cada um com sua conexão, todos sincronizados para atacar a
mesma linha no mesmo instante — contra um lote com **um** lugar.

Exatamente um vence; nove recebem `estoque-insuficiente`.

Removendo o `LockMode::PESSIMISTIC_WRITE` do repositório, o mesmo teste
registra **cinco** vendas para um lugar. É o bug que o projeto existe para
impedir, e o teste o pega.

## Estado atual

**Pronto**

- Front completo — 9 rotas cobrindo as telas dos três perfis (comprador, organizador, portaria)
- API: Symfony 8.1 nas quatro camadas, `/health` verificando banco e fila
- **Domínio puro** — `Lote`, `Reserva`, `Ingresso`, `Evento` e Value Objects, sem framework. 45 testes em 13ms
- **Lock pessimista e o teste de concorrência** — 10 processos disputando 1 lugar, exatamente 1 vence
- Esquema com `CHECK (quantidade_vendida <= quantidade_total)` e índice parcial na query mais quente
- Ambiente local completo em `docker compose`, e CI com os três portões
- Contador de reserva derivado do servidor, não de timer local
- Os dois 409 do sistema tratados como coisas diferentes, pelo campo `type` do RFC 7807
- Tipos derivados do contrato da API, não das telas
- PRD, plano de execução e 4 ADRs

**Falta**

- Autenticação e autorização (cadastro, login, Voters)
- Ligar o front na API: reservas, pagamento, painel e portaria de verdade
- Deploy: droplet, DNS e pipeline para o GHCR

A ordem de construção está em [PLAN.md §5](PLAN.md). O front foi feito fora de ordem, de propósito, e o [§2.1](PLAN.md) explica a decisão e o risco assumido.

## Sobre o `design/`

Referências hi-fi em HTML — aparência e comportamento pretendidos, **não** código de produção. Foram recriadas em Next.js e Tailwind; os arquivos originais ficam no repositório como fonte da verdade visual.

## Licença

MIT
