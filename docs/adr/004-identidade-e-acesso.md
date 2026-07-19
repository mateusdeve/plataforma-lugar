# ADR-004 — Contas, papéis acumuláveis e autorização por vínculo

**Status:** aceito
**Data:** julho de 2026
**Substitui:** PRD §6.3, que dispensava conta para o comprador

## Contexto

O PRD dizia que comprador não precisa de conta — apenas e-mail verificado por código. A justificativa era boa: cada campo entre a pessoa e o ingresso derruba conversão, e o e-mail já basta para entregar o ingresso.

A decisão mudou porque o sistema passou a ter três perfis com poderes bem diferentes, e porque "meus ingressos" só existe se houver a quem pertencer.

## Decisões

### 1. Comprador tem conta, e o convidado continua existindo

Conta habilita histórico e reemissão de ingresso. Convidado preserva o caminho curto que o PRD defendia com razão.

O custo é um caminho a mais para testar — a reserva precisa funcionar com e sem dono, e um convidado que cria conta depois deve encontrar seus ingressos pelo e-mail. Aceito conscientemente: é a diferença entre um sistema de venda e um formulário.

### 2. Papéis são acumuláveis

`usuario.papeis` é um array: `ROLE_COMPRADOR`, `ROLE_ORGANIZADOR`, `ROLE_PORTARIA`.

Papel exclusivo seria mais simples de modelar e falso na prática — um organizador conferindo a entrada do próprio evento é o caso comum, não a exceção. Um array não custa nada agora e evita uma migration constrangedora depois.

### 3. Autorização é por vínculo, não por papel

Esta é a decisão que importa.

`ROLE_ORGANIZADOR` diz que a pessoa pode organizar eventos. Não diz *quais*. Um sistema que confere apenas o papel deixa qualquer organizador ler o painel, os compradores e o faturamento de qualquer outro — é a falha de autorização mais comum que existe, e ela passa despercebida porque a tela nunca oferece o link.

Então:

- `EventoVoter` — organizador só alcança evento de que é dono
- `PortariaVoter` — operador só valida ingresso de evento em que está escalado, via `evento_operador`

Nada disso vira `if` em controller. Vira Voter, que é testável isoladamente e impossível de esquecer quando o endpoint seguinte for escrito.

### 4. A permissão é decidida no Symfony

O `middleware.ts` do Next protege rotas para que ninguém veja uma tela que não pode usar. **Isso é UX, não segurança.** Quem chama a API direto não passa por ele.

Consequência que vira regra de teste: para cada endpoint protegido, dois casos negativos — papel errado, e papel certo sem vínculo. O segundo é o que pega bug de verdade; o primeiro quase nunca falha.

## Detalhes

- Senha com Argon2id
- Access token JWT de 15 minutos, mantido em memória no cliente
- Refresh token em cookie `httpOnly`, rotacionado a cada uso, revogado no logout ([ADR-003](003-front-vercel-api-easypanel.md) trata dos atributos do cookie)
- Rate limit no login: 5 tentativas por minuto, por IP **e** por e-mail — só por IP não protege contra ataque distribuído a uma conta específica
- Sem CPF. Nome e e-mail bastam, e o mínimo de dado pessoal é o mínimo de obrigação de LGPD

## Consequências

O escopo cresce: uma fase inteira (fase 3) que o PRD não previa, mais telas de login e cadastro que o pacote de design não cobre e precisam ser desenhadas a partir dos tokens existentes.

Em troca, o projeto ganha o que talvez seja seu segundo melhor argumento técnico, depois do teste de concorrência: um teste que prova que um organizador autenticado, com token válido e papel correto, recebe 403 ao tentar ler o painel de um evento alheio.
