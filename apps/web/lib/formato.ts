import type { Dinheiro } from "./tipos";

/**
 * Dinheiro chega em centavos e é formatado só na borda da tela.
 * Nenhum cálculo monetário acontece no front — a API manda o total pronto.
 */
export function formatarDinheiro(valor: Dinheiro): string {
  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: valor.moeda,
    minimumFractionDigits: valor.centavos % 100 === 0 ? 0 : 2,
  }).format(valor.centavos / 100);
}

const DIAS = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
const MESES = [
  "jan",
  "fev",
  "mar",
  "abr",
  "mai",
  "jun",
  "jul",
  "ago",
  "set",
  "out",
  "nov",
  "dez",
];

/** "sáb · 12 set · 9h" */
export function formatarDataEvento(iso: string): string {
  const d = new Date(iso);
  const hora =
    d.getMinutes() === 0
      ? `${d.getHours()}h`
      : `${d.getHours()}h${String(d.getMinutes()).padStart(2, "0")}`;
  return `${DIAS[d.getDay()]} · ${d.getDate()} ${MESES[d.getMonth()]} · ${hora}`;
}

/** Dia e mês para o chip do card da vitrine: { dia: "12", mes: "SET" } */
export function chipDeData(iso: string): { dia: string; mes: string } {
  const d = new Date(iso);
  return {
    dia: String(d.getDate()).padStart(2, "0"),
    mes: MESES[d.getMonth()].toUpperCase(),
  };
}

/** "19h42" — usado na recusa da portaria (RN-10). */
export function formatarHora(iso: string): string {
  const d = new Date(iso);
  return `${d.getHours()}h${String(d.getMinutes()).padStart(2, "0")}`;
}

/** Segundos → "08:47". Usado pelo contador regressivo. */
export function formatarRelogio(segundos: number): string {
  const s = Math.max(0, segundos);
  const min = Math.floor(s / 60);
  const seg = s % 60;
  return `${String(min).padStart(2, "0")}:${String(seg).padStart(2, "0")}`;
}
