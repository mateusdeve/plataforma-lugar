/**
 * Cliente HTTP da API.
 *
 * `NEXT_PUBLIC_API_URL` precisa do prefixo NEXT_PUBLIC porque o contador da
 * reserva e o leitor da portaria rodam no navegador — variável sem esse
 * prefixo só existe no servidor, e a chamada quebraria em produção com uma
 * URL vazia.
 *
 * `credentials: "include"` é o que faz o cookie httpOnly de refresh viajar
 * junto. Funciona porque front e API são same-site sob o mesmo domínio
 * registrável (ADR-003); com hosts sem parentesco, o navegador descartaria o
 * cookie e a sessão nunca sobreviveria a um recarregamento.
 */
const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

/*
  ─────────────────────────────────────────────────────────────────────────────
  O ACCESS TOKEN VIVE AQUI, EM MEMÓRIA

  Um módulo, uma variável, e nada de localStorage — o raciocínio completo está
  em lib/sessao.tsx. O que importa neste arquivo é ONDE ele mora: no cliente
  HTTP, e não no contexto do React.

  Se morasse no contexto, só componentes conseguiriam anexá-lo, e cada chamada
  autenticada precisaria lembrar de fazê-lo. Um esquecimento não quebra a tela
  de forma óbvia: a requisição sai sem credencial, a API responde 401 e a
  pessoa vê "erro ao carregar". Aqui, toda requisição sai autenticada por
  construção — inclusive as que ainda não foram escritas.

  Vai no header `Authorization`, não em cookie: cookie o navegador envia
  sozinho, inclusive em requisição disparada por outro site, que é a definição
  de CSRF. Header exige que o nosso código o coloque.
  ─────────────────────────────────────────────────────────────────────────────
*/
let accessToken: string | null = null;

export function definirToken(token: string | null): void {
  accessToken = token;
}

export function tokenAtual(): string | null {
  return accessToken;
}

export class ErroDaApi extends Error {
  constructor(
    readonly status: number,
    /**
     * O campo `type` do problem+json. É por ele que a tela decide o que
     * fazer, nunca pela mensagem — os dois 409 do sistema têm o mesmo status
     * e tratamentos completamente diferentes (PRD §9).
     */
    readonly tipo: string,
    readonly titulo: string,
    detalhe?: string,
    /**
     * Membro de extensão do problem+json (RFC 7807).
     *
     * Hoje só a recusa da RN-10 usa: a portaria precisa dizer QUANDO a pessoa
     * entrou. O horário também aparece dentro do `detail`, e seria tentador
     * extrair de lá com uma expressão regular — que quebra na primeira vez que
     * alguém melhorar a frase. `detail` é para humanos lerem.
     */
    readonly utilizadoEm: string | null = null,
  ) {
    super(detalhe ?? titulo);
    this.name = "ErroDaApi";
  }

  /** ".../erros/estoque-insuficiente" → "estoque-insuficiente" */
  get chave(): string {
    return this.tipo.split("/").pop() ?? "";
  }
}

type Problema = {
  type?: string;
  title?: string;
  detail?: string;
  utilizadoEm?: string;
};

async function requisitar<T>(caminho: string, init: RequestInit = {}): Promise<T> {
  const resposta = await fetch(`${BASE}${caminho}`, {
    ...init,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(accessToken === null ? {} : { Authorization: `Bearer ${accessToken}` }),
      ...(init.headers ?? {}),
    },
  });

  if (resposta.status === 204) return undefined as T;

  const corpo: unknown = await resposta.json().catch(() => null);

  if (!resposta.ok) {
    const problema = (corpo ?? {}) as Problema;
    throw new ErroDaApi(
      resposta.status,
      problema.type ?? "",
      problema.title ?? "Erro",
      problema.detail,
      problema.utilizadoEm ?? null,
    );
  }

  return corpo as T;
}

export const api = {
  get: <T>(caminho: string, init?: RequestInit) =>
    requisitar<T>(caminho, { ...init, method: "GET" }),

  post: <T>(caminho: string, corpo?: unknown, init?: RequestInit) =>
    requisitar<T>(caminho, {
      ...init,
      method: "POST",
      body: corpo === undefined ? undefined : JSON.stringify(corpo),
    }),

  remover: <T>(caminho: string, init?: RequestInit) =>
    requisitar<T>(caminho, { ...init, method: "DELETE" }),
};
