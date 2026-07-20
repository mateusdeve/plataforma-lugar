import { api, ErroDaApi } from "./api";
import type {
  EventoDetalhe,
  EventoDoOrganizador,
  EventoResumo,
  Ingresso,
  PainelOrganizador,
  Reserva,
  ResultadoValidacao,
} from "./tipos";

/*
  ─────────────────────────────────────────────────────────────────────────────
  COSTURA COM A API

  Este é o único arquivo que sabe de onde os dados vêm. Sobrou UM mock: a
  validação na portaria, que depende dos endpoints da fase 7.

    buscarEventos()        → GET  /api/eventos            ✔ ligado
    buscarEvento(id)       → GET  /api/eventos/{id}       ✔ ligado
    buscarReserva(id)      → GET  /api/reservas/{id}      ✔ ligado
    criarReserva(...)      → POST /api/reservas           ✔ ligado
    cancelarReserva(id)    → DELETE /api/reservas/{id}    ✔ ligado
    buscarMeusEventos()    → GET  /api/organizador/eventos          ✔ ligado
    buscarPainel(eventoId) → GET  /api/organizador/…/painel         ✔ ligado
    iniciarCheckout(id)    → POST /api/reservas/{id}/checkout       ✔ ligado
    simularPagamento(id)   → POST /api/reservas/{id}/simular-…      ✔ ligado
    buscarIngressosDaReserva(id) → GET /api/reservas/{id}/ingressos ✔ ligado
    validarIngresso(...)   → POST /api/ingressos/…        ○ fase 7
  ─────────────────────────────────────────────────────────────────────────────
*/

// ── leitura ─────────────────────────────────────────────────────────────────

export async function buscarEventos(): Promise<EventoResumo[]> {
  const { itens } = await api.get<{ itens: EventoResumo[] }>("/api/eventos");
  return itens;
}

export async function buscarEvento(id: string): Promise<EventoDetalhe | null> {
  try {
    return await api.get<EventoDetalhe>(`/api/eventos/${id}`);
  } catch {
    // 404 vira null e a página chama notFound(); qualquer outra falha também
    // resulta em "evento não encontrado", que é mais honesto na tela do que
    // uma stack trace.
    return null;
  }
}

/** POST /api/reservas — o caminho que passa pelo lock pessimista. */
export async function criarReserva(entrada: {
  loteId: string;
  quantidade: number;
  compradorEmail: string;
  chaveDeIdempotencia: string;
}): Promise<Reserva> {
  return api.post<Reserva>(
    "/api/reservas",
    {
      loteId: entrada.loteId,
      quantidade: entrada.quantidade,
      compradorEmail: entrada.compradorEmail,
    },
    // A chave repetida numa retentativa devolve a MESMA reserva (PRD §6.2).
    { headers: { "Idempotency-Key": entrada.chaveDeIdempotencia } },
  );
}

export async function cancelarReserva(id: string): Promise<void> {
  await api.remover(`/api/reservas/${id}`);
}

/**
 * Reserva de demonstração. `expiraEm` é calculado a partir do instante da
 * requisição para que o contador da tela de checkout tenha algo real para
 * derivar — é exatamente o que a API fará ao criar a reserva.
 */
export async function buscarReserva(id: string): Promise<Reserva | null> {
  try {
    return await api.get<Reserva>(`/api/reservas/${id}`);
  } catch {
    return null;
  }
}

// ── pagamento e emissão (fase 5) ────────────────────────────────────────────

/** POST /api/reservas/{id}/checkout — abre a cobrança no gateway. */
export async function iniciarCheckout(reservaId: string): Promise<{
  referencia: string;
  urlDePagamento: string;
  expiraEm: string;
  provedor: string;
}> {
  return api.post(`/api/reservas/${reservaId}/checkout`);
}

/**
 * POST /api/reservas/{id}/simular-pagamento
 *
 * Faz o papel do provedor enquanto não há gateway real configurado: a API monta
 * o webhook assinado e o manda pela mesma verificação HMAC de produção. A rota
 * responde 404 fora de dev/test, de propósito — em produção ela seria ingresso
 * de graça para quem descobrisse a URL.
 */
export async function simularPagamento(
  reservaId: string,
): Promise<{ status: string; ingressosEmitidos: number }> {
  return api.post(`/api/reservas/${reservaId}/simular-pagamento`);
}

/** GET /api/reservas/{id}/ingressos — os ingressos emitidos da compra. */
export async function buscarIngressosDaReserva(
  reservaId: string,
): Promise<Ingresso[]> {
  const { itens } = await api.get<{ itens: Ingresso[] }>(
    `/api/reservas/${reservaId}/ingressos`,
  );
  return itens;
}

// ── organizador ─────────────────────────────────────────────────────────────

/**
 * GET /api/organizador/eventos — só os do dono do token.
 *
 * Repare que não há parâmetro de organizador: a API decide de quem é a lista
 * pelo token, e não por algo que a tela mandaria. Um `?organizadorId=` aqui
 * seria a agenda de qualquer um a um caractere de distância.
 */
export async function buscarMeusEventos(): Promise<EventoDoOrganizador[]> {
  const { itens } = await api.get<{ itens: EventoDoOrganizador[] }>(
    "/api/organizador/eventos",
  );
  return itens;
}

/**
 * GET /api/organizador/eventos/{id}/painel.
 *
 * 403 aqui NÃO é tratado como "não encontrado": é o `EventoVoter` dizendo que
 * o evento existe e não é seu (ADR-004). O erro sobe para a tela distinguir os
 * dois casos — confundi-los esconderia justamente a regra que o projeto quer
 * demonstrar.
 */
export async function buscarPainel(eventoId: string): Promise<PainelOrganizador> {
  return api.get<PainelOrganizador>(
    `/api/organizador/eventos/${eventoId}/painel`,
  );
}

// ── portaria (fase 7) ───────────────────────────────────────────────────────

/**
 * GET /api/portaria/{eventoId}
 *
 * Responde 403 se o operador não estiver escalado neste evento. Chamar isto na
 * abertura da tela faz quem errou a porta descobrir agora, e não com a fila na
 * frente.
 */
export async function buscarEstadoDaPorta(eventoId: string): Promise<{
  eventoId: string;
  eventoTitulo: string;
  entradas: number;
}> {
  return api.get(`/api/portaria/${eventoId}`);
}

/**
 * POST /api/ingressos/{codigo}/utilizar
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O VEREDITO VEM DO SERVIDOR, SEMPRE.
 *
 * A versão anterior guardava os códigos já lidos num objeto no navegador, para
 * a segunda leitura recusar. Funcionava numa catraca só — e é exatamente o bug
 * que a RN-10 existe para impedir: com dois leitores, cada um tem a sua
 * memória, e o mesmo ingresso entra duas vezes.
 *
 * Agora nada é decidido aqui. O servidor valida sob `SELECT ... FOR UPDATE`, e
 * a tela só traduz a resposta. `eventoId` diz de qual porta veio a leitura;
 * quem confere se o operador pode validar naquela porta é o PortariaVoter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
export async function validarIngresso(
  codigo: string,
  eventoId: string,
): Promise<ResultadoValidacao> {
  const normalizado = codigo.trim().toUpperCase();

  try {
    const { ingresso } = await api.post<{
      ingresso: {
        compradorNome: string | null;
        compradorEmail: string;
        loteNome: string;
        quantidade: number;
      } | null;
      entradas: number;
    }>(`/api/ingressos/${encodeURIComponent(normalizado)}/utilizar`, { eventoId });

    return {
      entra: true,
      codigo: normalizado,
      // Convidado não tem conta e portanto não tem nome (ADR-004). Na porta,
      // o e-mail já serve para resolver discussão.
      compradorNome: ingresso?.compradorNome ?? ingresso?.compradorEmail ?? null,
      loteNome: ingresso?.loteNome ?? "",
      quantidade: ingresso?.quantidade ?? 1,
    };
  } catch (erro) {
    return recusa(normalizado, erro);
  }
}

/**
 * A recusa é escolhida pelo `type` do problem+json, NUNCA pela mensagem.
 *
 * São quatro motivos com telas e consequências diferentes, e alguns
 * compartilham status HTTP. Ler a frase quebraria no primeiro ajuste de texto.
 */
function recusa(codigo: string, erro: unknown): ResultadoValidacao {
  if (!(erro instanceof ErroDaApi)) {
    return { entra: false, codigo, motivo: "CODIGO_INVALIDO", utilizadoEm: null };
  }

  switch (erro.chave) {
    case "ingresso-ja-utilizado":
      return {
        entra: false,
        codigo,
        motivo: "JA_UTILIZADO",
        // Campo próprio do problem+json, não extraído do texto — ver o
        // comentário em TradutorDeExcecoes::extrasDe().
        utilizadoEm: erro.utilizadoEm,
      };

    case "ingresso-de-outro-evento":
      return { entra: false, codigo, motivo: "EVENTO_ERRADO", utilizadoEm: null };

    default:
      return { entra: false, codigo, motivo: "CODIGO_INVALIDO", utilizadoEm: null };
  }
}
