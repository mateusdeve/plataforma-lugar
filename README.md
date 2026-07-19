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
| API | PHP 8.3, Symfony 7, Doctrine — no EasyPanel |
| Banco | PostgreSQL 16, sem porta pública |
| Fila | Symfony Messenger |

O `Domain/` da API não importa `Symfony\` nem `Doctrine\`, e um teste de arquitetura quebra o CI se alguém tentar.

## Rodando o front

```bash
cd apps/web
npm install
npm run dev
```

`http://localhost:3000/guia` lista todas as telas com a origem no design e o endpoint que cada uma vai consumir.

## Estado atual

**Pronto**

- Front completo — 9 rotas cobrindo as telas dos três perfis (comprador, organizador, portaria)
- Contador de reserva derivado do servidor, não de timer local
- Os dois 409 do sistema tratados como coisas diferentes, pelo campo `type` do RFC 7807
- Tipos derivados do contrato da API, não das telas
- PRD, plano de execução e 4 ADRs

**Falta**

- `apps/api` — o domínio, a persistência e o **teste de concorrência**, que é o item mais importante do projeto
- Autenticação e autorização (cadastro, login, Voters)
- Infraestrutura: Docker Compose, CI, deploy

A ordem de construção está em [PLAN.md §5](PLAN.md). O front foi feito fora de ordem, de propósito, e o [§2.1](PLAN.md) explica a decisão e o risco assumido.

## Sobre o `design/`

Referências hi-fi em HTML — aparência e comportamento pretendidos, **não** código de produção. Foram recriadas em Next.js e Tailwind; os arquivos originais ficam no repositório como fonte da verdade visual.

## Licença

MIT
