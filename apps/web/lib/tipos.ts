/*
  Tipos derivados do contrato da API — PRD §8 (modelo de dados) e §9 (endpoints).
  Nada aqui é inventado para servir a tela: quando o Symfony existir, a fonte
  dos dados muda e estes tipos continuam valendo. É o que separa uma casca de
  uma implementação pela metade.
*/

// ── dinheiro ────────────────────────────────────────────────────────────────
// Sempre centavos, inteiro. Nunca float. PRD §8.
export type Dinheiro = {
  centavos: number;
  moeda: "BRL";
};

// ── evento ──────────────────────────────────────────────────────────────────
export type EventoStatus = "RASCUNHO" | "PUBLICADO" | "CANCELADO";

/** Situação agregada do evento na vitrine — derivada dos lotes pela API. */
export type SituacaoEvento = "DISPONIVEL" | "ULTIMOS" | "ESGOTADO" | "EM_BREVE";

export type EventoResumo = {
  id: string;
  titulo: string;
  local: string;
  cidade: string;
  /** ISO 8601 com timezone. Formatação é responsabilidade do cliente. */
  iniciaEm: string;
  situacao: SituacaoEvento;
  /** Quantos restam, quando `situacao === "ULTIMOS"`. */
  restantes: number | null;
  precoMinimo: Dinheiro | null;
};

export type EventoDetalhe = EventoResumo & {
  descricao: string;
  lotes: Lote[];
  /** RN-01: configurável por evento, entre 5 e 30 minutos. */
  prazoReservaMinutos: number;
};

// ── lote ────────────────────────────────────────────────────────────────────
export type SituacaoLote = "DISPONIVEL" | "ESGOTADO" | "EM_BREVE" | "ENCERRADO";

export type Lote = {
  id: string;
  nome: string;
  preco: Dinheiro;
  /**
   * Calculado pela API conforme ADR-002 (expiração preguiçosa):
   * total − vendida − reservas PENDENTES ainda não expiradas.
   * Pode estar até 5s desatualizado na vitrine; nunca na criação da reserva.
   */
  disponivel: number;
  situacao: SituacaoLote;
  vendasIniciamEm: string;
  vendasTerminamEm: string | null;
};

// ── reserva ─────────────────────────────────────────────────────────────────
// Espelho da máquina de estados do domínio. PRD §4.3 — os três finais são terminais.
export type ReservaStatus =
  | "PENDENTE"
  | "CONFIRMADA"
  | "EXPIRADA"
  | "CANCELADA";

export type Reserva = {
  id: string;
  eventoId: string;
  loteId: string;
  quantidade: number;
  status: ReservaStatus;
  /** ISO 8601. É a fonte de verdade do contador — nunca um timer local. */
  expiraEm: string;
  /**
   * Enviado pela API junto de `expiraEm` para corrigir defasagem de relógio
   * entre servidor e navegador. GET /api/reservas/{id}.
   */
  segundosRestantes: number;
  total: Dinheiro;
};

// ── ingresso ────────────────────────────────────────────────────────────────
export type IngressoStatus = "EMITIDO" | "UTILIZADO" | "CANCELADO";

export type Ingresso = {
  /** RN-09: aleatório, não sequencial. Formato LGR-XXXX-XXXX. */
  codigo: string;
  status: IngressoStatus;
  eventoTitulo: string;
  eventoIniciaEm: string;
  eventoLocal: string;
  loteNome: string;
  compradorNome: string | null;
  compradorEmail: string;
  quantidade: number;
  utilizadoEm: string | null;
};

// ── validação na portaria ───────────────────────────────────────────────────
export type MotivoRecusa =
  | "JA_UTILIZADO"
  | "CODIGO_INVALIDO"
  | "EVENTO_ERRADO"
  | "CANCELADO";

export type ResultadoValidacao =
  | {
      entra: true;
      codigo: string;
      compradorNome: string | null;
      loteNome: string;
      quantidade: number;
    }
  | {
      entra: false;
      codigo: string;
      motivo: MotivoRecusa;
      /** Preenchido quando motivo === "JA_UTILIZADO". RN-10. */
      utilizadoEm: string | null;
    };

// ── painel do organizador ───────────────────────────────────────────────────
export type PainelOrganizador = {
  evento: {
    id: string;
    titulo: string;
    iniciaEm: string;
    local: string;
    cidade: string;
    status: EventoStatus;
  };
  vendidos: number;
  vendidosHoje: number;
  /** Reservas PENDENTES não expiradas neste instante. */
  reservadosAgora: number;
  disponiveis: number;
  receitaConfirmada: Dinheiro;
  receitaLiquida: Dinheiro;
  /** Métrica de negócio exigida pelo PRD §6.5. */
  conversao: { viraramVenda: number; expiraram: number };
  ocupacaoPorLote: Array<{
    loteId: string;
    nome: string;
    preco: Dinheiro;
    vendidos: number;
    total: number;
    situacao: SituacaoLote;
    vendasIniciamEm: string;
  }>;
  compradores: Comprador[];
};

export type Comprador = {
  reservaId: string;
  nome: string;
  email: string;
  loteNome: string;
  quantidade: number;
  status: ReservaStatus;
  /** Só para status PENDENTE — quanto falta expirar, formato mm:ss. */
  expiraEm: string | null;
};

// ── erros (RFC 7807) ────────────────────────────────────────────────────────
/*
  O front distingue os dois 409 pelo campo `type`, nunca pela mensagem.
  Mensagens diferentes, tratamentos diferentes. PRD §9.
*/
export const TIPO_ERRO = {
  estoqueInsuficiente: "https://comprarbem.store/erros/estoque-insuficiente",
  reservaExpirada: "https://comprarbem.store/erros/reserva-expirada",
  limiteReservasAtivas: "https://comprarbem.store/erros/limite-reservas-ativas",
  foraDaJanelaDeVenda: "https://comprarbem.store/erros/fora-da-janela-de-venda",
  ingressoJaUtilizado: "https://comprarbem.store/erros/ingresso-ja-utilizado",
} as const;

export type TipoErro = (typeof TIPO_ERRO)[keyof typeof TIPO_ERRO];

/** application/problem+json */
export type ProblemDetail = {
  type: TipoErro | string;
  title: string;
  status: number;
  detail?: string;
  instance?: string;
};
