import { api } from "./api";
import type {
  EventoDetalhe,
  EventoResumo,
  Ingresso,
  PainelOrganizador,
  Reserva,
  ResultadoValidacao,
} from "./tipos";

/*
  ─────────────────────────────────────────────────────────────────────────────
  COSTURA COM A API

  Este é o único arquivo que sabe de onde os dados vêm. As funções abaixo já
  chamam a API Symfony; o que sobrou de exemplo estático são as telas ainda
  não ligadas (painel do organizador e portaria), marcadas uma a uma.

    buscarEventos()        → GET  /api/eventos            ✔ ligado
    buscarEvento(id)       → GET  /api/eventos/{id}       ✔ ligado
    buscarReserva(id)      → GET  /api/reservas/{id}      ✔ ligado
    criarReserva(...)      → POST /api/reservas           ✔ ligado
    cancelarReserva(id)    → DELETE /api/reservas/{id}    ✔ ligado
    buscarIngresso(codigo) → GET  /api/ingressos/{codigo} ○ fase 5
    buscarPainel(eventoId) → GET  /api/organizador/…      ○ fase 6
    validarIngresso(...)   → POST /api/ingressos/…        ○ fase 7
  ─────────────────────────────────────────────────────────────────────────────
*/

const brl = (reais: number) => ({ centavos: reais * 100, moeda: "BRL" as const });

const EVENTOS: EventoDetalhe[] = [
  {
    id: "frontz-conf-2026",
    titulo: "FrontZ Conf 2026",
    local: "Teatro B32",
    cidade: "São Paulo",
    iniciaEm: "2026-09-12T09:00:00-03:00",
    situacao: "DISPONIVEL",
    restantes: null,
    precoMinimo: brl(220),
    prazoReservaMinutos: 10,
    descricao:
      "Um dia inteiro sobre o front-end que a gente escreve de verdade: performance, acessibilidade, arquitetura de componentes e as decisões difíceis entre uma sprint e outra. Palestras curtas, corredor longo — o melhor acontece no café.",
    lotes: [
      {
        id: "frontz-lote-1",
        nome: "1º lote",
        preco: brl(180),
        disponivel: 0,
        situacao: "ESGOTADO",
        vendasIniciamEm: "2026-03-01T00:00:00-03:00",
        vendasTerminamEm: "2026-06-30T23:59:59-03:00",
      },
      {
        id: "frontz-lote-2",
        nome: "2º lote",
        preco: brl(220),
        disponivel: 74,
        situacao: "DISPONIVEL",
        vendasIniciamEm: "2026-07-01T00:00:00-03:00",
        vendasTerminamEm: "2026-07-31T23:59:59-03:00",
      },
      {
        id: "frontz-lote-3",
        nome: "3º lote",
        preco: brl(260),
        disponivel: 150,
        situacao: "EM_BREVE",
        vendasIniciamEm: "2026-08-01T00:00:00-03:00",
        vendasTerminamEm: null,
      },
    ],
  },
  {
    id: "encontro-php-do-sul",
    titulo: "Encontro PHP do Sul",
    local: "Auditório do Caldeira",
    cidade: "Porto Alegre",
    iniciaEm: "2026-10-03T14:00:00-03:00",
    situacao: "ULTIMOS",
    restantes: 6,
    precoMinimo: brl(140),
    prazoReservaMinutos: 10,
    descricao:
      "PHP moderno, sem nostalgia e sem defensiva: tipos, arquitetura, filas e o que mudou de verdade nos últimos anos. Um sábado à tarde com quem mantém sistema grande em produção.",
    lotes: [
      {
        id: "php-sul-lote-unico",
        nome: "Lote único",
        preco: brl(140),
        disponivel: 6,
        situacao: "DISPONIVEL",
        vendasIniciamEm: "2026-05-01T00:00:00-03:00",
        vendasTerminamEm: "2026-10-02T23:59:59-03:00",
      },
    ],
  },
  {
    id: "workshop-ddd-na-pratica",
    titulo: "Workshop — DDD na prática",
    local: "Aldeia Cowork",
    cidade: "Curitiba",
    iniciaEm: "2026-10-17T09:00:00-03:00",
    situacao: "ESGOTADO",
    restantes: null,
    precoMinimo: brl(320),
    prazoReservaMinutos: 15,
    descricao:
      "Oito horas modelando um domínio real em grupo. Sem slide sobre o que é agregado — a gente descobre a fronteira errando e corrigindo, que é como se aprende.",
    lotes: [
      {
        id: "ddd-lote-unico",
        nome: "Lote único",
        preco: brl(320),
        disponivel: 0,
        situacao: "ESGOTADO",
        vendasIniciamEm: "2026-06-01T00:00:00-03:00",
        vendasTerminamEm: "2026-10-16T23:59:59-03:00",
      },
    ],
  },
  {
    id: "nextconf-brasil",
    titulo: "NextConf Brasil",
    local: "Cidade das Artes",
    cidade: "Rio de Janeiro",
    iniciaEm: "2026-10-24T10:00:00-03:00",
    situacao: "DISPONIVEL",
    restantes: null,
    precoMinimo: brl(190),
    prazoReservaMinutos: 10,
    descricao:
      "Renderização, cache e as escolhas que sobram quando o framework já decidiu quase tudo. Conteúdo para quem já colocou Next em produção e apanhou.",
    lotes: [
      {
        id: "nextconf-lote-1",
        nome: "1º lote",
        preco: brl(190),
        disponivel: 210,
        situacao: "DISPONIVEL",
        vendasIniciamEm: "2026-06-15T00:00:00-03:00",
        vendasTerminamEm: "2026-10-23T23:59:59-03:00",
      },
    ],
  },
];

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

export async function buscarIngresso(codigo: string): Promise<Ingresso | null> {
  const evento = EVENTOS[0];
  return {
    codigo,
    status: "EMITIDO",
    eventoTitulo: evento.titulo,
    eventoIniciaEm: evento.iniciaEm,
    eventoLocal: `${evento.local}, ${evento.cidade}`,
    loteNome: "2º lote",
    compradorNome: "Ana Souza",
    compradorEmail: "ana@email.com",
    quantidade: 2,
    utilizadoEm: null,
  };
}

export async function buscarPainel(
  eventoId: string,
): Promise<PainelOrganizador | null> {
  const evento = EVENTOS.find((e) => e.id === eventoId);
  if (!evento) return null;

  return {
    evento: {
      id: evento.id,
      titulo: evento.titulo,
      iniciaEm: evento.iniciaEm,
      local: evento.local,
      cidade: evento.cidade,
      status: "PUBLICADO",
    },
    vendidos: 436,
    vendidosHoje: 28,
    reservadosAgora: 12,
    disponiveis: 74,
    receitaConfirmada: brl(87_920),
    receitaLiquida: brl(81_400),
    conversao: { viraramVenda: 82, expiraram: 18 },
    ocupacaoPorLote: [
      {
        loteId: "frontz-lote-1",
        nome: "1º lote",
        preco: brl(180),
        vendidos: 200,
        total: 200,
        situacao: "ESGOTADO",
        vendasIniciamEm: "2026-03-01T00:00:00-03:00",
      },
      {
        loteId: "frontz-lote-2",
        nome: "2º lote",
        preco: brl(220),
        vendidos: 236,
        total: 310,
        situacao: "DISPONIVEL",
        vendasIniciamEm: "2026-07-01T00:00:00-03:00",
      },
      {
        loteId: "frontz-lote-3",
        nome: "3º lote",
        preco: brl(260),
        vendidos: 0,
        total: 150,
        situacao: "EM_BREVE",
        vendasIniciamEm: "2026-08-01T00:00:00-03:00",
      },
    ],
    compradores: [
      {
        reservaId: "r-1",
        nome: "Ana Souza",
        email: "ana@email.com",
        loteNome: "2º lote",
        quantidade: 2,
        status: "CONFIRMADA",
        expiraEm: null,
      },
      {
        reservaId: "r-2",
        nome: "Bruno Lima",
        email: "bruno.l@dev.br",
        loteNome: "2º lote",
        quantidade: 1,
        status: "PENDENTE",
        expiraEm: "07:41",
      },
      {
        reservaId: "r-3",
        nome: "Carla Mendes",
        email: "carla@estudio.co",
        loteNome: "2º lote",
        quantidade: 4,
        status: "CONFIRMADA",
        expiraEm: null,
      },
      {
        reservaId: "r-4",
        nome: "Diego Rocha",
        email: "diego@rocha.dev",
        loteNome: "1º lote",
        quantidade: 1,
        status: "CONFIRMADA",
        expiraEm: null,
      },
      {
        reservaId: "r-5",
        nome: "Elisa Nunes",
        email: "elisa.n@mail.com",
        loteNome: "2º lote",
        quantidade: 2,
        status: "EXPIRADA",
        expiraEm: null,
      },
    ],
  };
}

// ── validação na portaria ───────────────────────────────────────────────────

/**
 * Mock determinístico enquanto a API não existe: o código conhecido de entrada
 * passa na primeira leitura; a segunda leitura do mesmo código recusa com o
 * horário da primeira (RN-10), e o estado vive na sessão do navegador.
 */
const CODIGO_VALIDO = "LGR-7Q2M-84KD";
const CODIGO_JA_UTILIZADO = "LGR-3XN9-51RT";

export async function validarIngresso(
  codigo: string,
  jaLidosNestaSessao: Record<string, string>,
): Promise<ResultadoValidacao> {
  const normalizado = codigo.trim().toUpperCase();

  const lidoAntes = jaLidosNestaSessao[normalizado];
  if (lidoAntes) {
    return {
      entra: false,
      codigo: normalizado,
      motivo: "JA_UTILIZADO",
      utilizadoEm: lidoAntes,
    };
  }

  if (normalizado === CODIGO_JA_UTILIZADO) {
    return {
      entra: false,
      codigo: normalizado,
      motivo: "JA_UTILIZADO",
      utilizadoEm: "2026-09-12T19:42:00-03:00",
    };
  }

  if (normalizado === CODIGO_VALIDO) {
    return {
      entra: true,
      codigo: normalizado,
      compradorNome: "Ana Souza",
      loteNome: "2º lote",
      quantidade: 1,
    };
  }

  return {
    entra: false,
    codigo: normalizado,
    motivo: "CODIGO_INVALIDO",
    utilizadoEm: null,
  };
}
