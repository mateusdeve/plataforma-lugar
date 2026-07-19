# Handoff — lugar. (Plataforma de venda de ingressos com reserva temporária)

## Visão geral
"lugar." é a interface da plataforma descrita no PRD: compradores reservam ingressos por tempo limitado (reserva temporária de 10 min) enquanto pagam; organizadores publicam eventos com lotes e acompanham vendas; a portaria valida ingressos na entrada com resposta binária. Este pacote contém **todas as telas do MVP em HTML, uma por arquivo, organizadas por perfil**.

## Sobre os arquivos deste pacote
Os arquivos são **referências de design criadas em HTML** — mostram aparência e comportamento pretendidos, **não são código de produção para copiar**. A tarefa é **recriar estas telas no ambiente do projeto: Next.js (App Router) + TypeScript + Tailwind**, conforme a stack do PRD (seção 7.1), consumindo a API Symfony descrita na seção 9. Links entre os arquivos simulam a navegação real.

## Fidelidade
**Alta (hi-fi).** Cores, tipografia, espaçamentos, raios e copy são finais. Recriar fielmente, mapeando os tokens abaixo para o tailwind.config.

## Estrutura
```
comprador/
  01-vitrine.html              lista pública de eventos (GET /api/eventos)
  02-evento.html               detalhe com lotes e disponibilidade (GET /api/eventos/{id})
  03-evento-esgotou-409.html   estado 409 type=estoque-insuficiente
  04-checkout.html             reserva ativa + pagamento (POST /api/reservas → checkout)
  05-confirmada.html           ingresso emitido (ReservaConfirmada)
  06-expirada.html             estado 409 type=reserva-expirada / timer zerou
organizador/
  01-painel.html               GET /api/organizador/eventos/{id}/painel
  02-novo-evento.html          POST /api/eventos + lotes
portaria/
  01-leitura.html              POST /api/ingressos/{codigo}/utilizar
  02-resultado-entra.html      resposta 200 — verde
  03-resultado-nao-entra.html  recusa — vermelho com motivo (RN-10)
index.html                     índice navegável de todas as telas
```

## Design tokens

### Cores
| Token | Hex | Uso |
|---|---|---|
| papel | #FAF6F0 | fundo do comprador |
| papel-org | #F6F1E8 | fundo do organizador |
| tinta | #201A16 | texto, superfícies escuras (countdown, ticket, header org) |
| tinta-portaria | #16120E | fundo da portaria e módulos do QR |
| primária | #E8501F | CTAs, marca, barra do countdown |
| primária-hover | #C74316 | hover de CTAs e links |
| link-hover | #A33310 | a:hover |
| sucesso | #1E7A52 | pills "Disponível/Confirmada", overlay PODE ENTRAR |
| sucesso-bg | #E3F0E7 | fundo de pills de sucesso |
| alerta | #B4690E | "Últimos N", reservas pendentes, countdown < 2min (#E09B3D barra / #F0C787 texto) |
| alerta-bg | #F7EEDA | fundo de pills de alerta |
| erro | #C0362C | overlay NÃO ENTRA; countdown < 30s: barra #E4553F, texto #FF9B84 + pulso |
| erro-409-bg / borda / texto | #FCEFE8 / #F0CDBA / #9E3412 | banner "esgotou enquanto você decidia" |
| borda-card | #EDE4D5 | bordas de cards claros |
| borda-input | #E2D8C8 | inputs (focus: primária) |
| texto-2 | #5C5348 | parágrafos |
| texto-3 | #7A6F63 | metadados |
| texto-4 | #9C9080 | labels uppercase, hints |
| desabilitado | #D8CDBC | CTA disabled, dígitos apagados |
| placeholder | #B9AC9A | ::placeholder |
| trilha | #F1EADD | fundos de barras de progresso claras (escura: #3A322B) |
| bronze | #B08968 | acentos sobre tinta (labels do ticket, mês no chip de data) |
| off-white | #FFF8F0 / #FFFDF9 | texto sobre primária / sobre overlays |

### Tipografia (Google Fonts)
- **Bricolage Grotesque** 700–800 — display: títulos, números grandes, CTAs, wordmark ("lugar" + ponto #E8501F)
- **Instrument Sans** 400–700 — corpo e UI
- **Spline Sans Mono** 400–700 — códigos de ingresso, contador, valores tabulares
- Escala: hero clamp(40–64px, ls −1.5px) · h1 30–42px · card 20px · corpo 15–16px · meta 13–14.5px · labels uppercase 13px ls 1.5px · countdown 22px · overlay portaria clamp(56–120px)

### Forma e espaço
- Raios: cards 16px · inputs 10px · botões 14px · pills 99px · ticket 20px · portaria input/botão 16px
- Sombras: countdown sticky 0 12px 28px rgba(32,26,22,.25) · ticket 0 20px 44px rgba(32,26,22,.28) · hover de card 0 10px 24px rgba(60,44,20,.08) + translateY(−2px)
- Grid da vitrine: repeat(auto-fill, minmax(300px,1fr)) gap 16 · painel: stats minmax(200px,1fr), colunas minmax(340px,1fr) gap 14
- Larguras: vitrine 1080px · fluxo comprador 640px · confirmação 560px · painel 1120px · form organizador 720px
- Alvos de toque ≥ 44px (stepper 46px, botões portaria 60px+)

## Interações e comportamento
- **Contador regressivo (crítico)**: calculado SEMPRE a partir de `expiraEm` retornado pelo servidor (GET /api/reservas/{id} → segundos restantes), nunca de um timer iniciado no navegador. Recarregar a página mantém o tempo. Estados: normal (barra #E8501F) → <2min (âmbar) → <30s (vermelho + animação de pulso 1s). Ao zerar: transição para a tela Expirada e o front trata como estoque devolvido (lazy expiration, ADR-002).
- **409 com dois significados** (RFC 7807, campo `type`): `estoque-insuficiente` → banner na tela do evento (03); `reserva-expirada` → tela Expirada (06). Mensagens e tratamentos diferentes — nunca erro genérico.
- **Idempotência**: POST /reservas envia header `Idempotency-Key` (UUID gerado no clique); repetição em rede móvel não pode duplicar reserva.
- **Stepper**: 1–6 (RN-04), teto também limitado pelo disponível do lote (RN-03).
- **Lotes**: esgotado e "em breve" não selecionáveis (opacity .6); selecionado tem borda #E8501F e radio preenchido.
- **Cancelar reserva**: link discreto "Desistir e liberar meu lugar" → DELETE /api/reservas/{id} → volta ao evento + toast "Reserva liberada — o lugar voltou pro estoque."
- **Pagamento**: botão vira "Confirmando…" (bg #B08968) durante processamento (~1.4s no mock); e-mail obrigatório antes de pagar.
- **Portaria**: Enter no input dispara validação; input força maiúsculas; resposta em tela cheia (verde/vermelha) com motivo + código, auto-dismiss em 2.8s ou toque; contador de entradas no header. Recusa sempre com motivo: "Já utilizado às HHhMM" (RN-10), "Código inválido para este evento".
- **CSV**: botão Exportar CSV → download + toast "compradores.csv exportado — N linhas."
- **Animações**: entrada de tela fadeUp .35s ease (opacity + translateY 10px) · overlay portaria popIn .18s · toast top-center 3.2s · transição de largura da barra .3s linear.
- **Toasts**: pill #201A16, topo centro, texto 14.5px/600.

## Gestão de estado (front)
- Comprador: `tela` (vitrine|evento|checkout|expirada|confirmada), `reserva {id, expiraEm, qty}`, `loteSelecionado`, `qty`, `erro409`. Máquina da reserva (espelho do domínio): PENDENTE → CONFIRMADA | EXPIRADA | CANCELADA (terminais; expirada nunca é reaproveitada — cria-se outra).
- Disponibilidade da vitrine pode ser eventualmente consistente (até 5s, polling ou revalidação); a criação da reserva, nunca.
- Portaria: `input`, `resultado {ok, motivo, codigo}`, contagem de entradas.

## Dados de exemplo usados
Eventos tech: FrontZ Conf 2026 (SP, 3 lotes: R$ 180 esgotado / R$ 220 com 74 / R$ 260 abre 1 ago), Encontro PHP do Sul (POA, últimos 6, R$ 140), Workshop DDD (Curitiba, esgotado), NextConf Brasil (RJ, R$ 190). Painel: 436 vendidos, 12 reservados, 74 disponíveis, R$ 87.920, conversão 82/18. Formato de código: `LGR-XXXX-XXXX` (charset sem ambíguos: ABCDEFGHJKMNPQRSTUVWXYZ23456789).

## Assets
Nenhum binário. Fontes via Google Fonts. O QR do ingresso é ilustrativo (grade 17×17 gerada por hash do código) — em produção usar uma lib real de QR (ex.: `qrcode`) apontando para o código único do ingresso.

## Arquivos de referência
Todos listados na Estrutura acima; `index.html` navega por todos. O protótipo interativo original (fluxo completo com timer real, simulações de corrida/expiração e validação de portaria funcional) é `Plataforma Lugar.dc.html` no workspace de design.
