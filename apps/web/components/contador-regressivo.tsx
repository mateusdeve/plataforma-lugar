"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { formatarRelogio } from "@/lib/formato";

/*
  ─────────────────────────────────────────────────────────────────────────────
  O tempo vem do servidor. Sempre.

  A tentação é receber "10 minutos" e rodar um setInterval decrescente. Isso
  quebra de três formas: recarregar a página reinicia a contagem, o relógio do
  navegador pode estar adiantado, e uma aba suspensa acorda mentindo.

  Aqui o servidor manda `segundosRestantes` junto de `expiraEm`
  (GET /api/reservas/{id}). Na montagem, traduzimos esse valor para um instante
  no relógio LOCAL — a âncora — e daí em diante todo tick é uma subtração
  contra `Date.now()`, não um decremento acumulado.

  Consequências:
    · recarregar busca o valor do servidor de novo e acerta o tempo
    · defasagem entre relógio do servidor e do navegador não importa: a âncora
      já nasce corrigida, porque foi construída a partir de um intervalo
      (segundos restantes), não de um horário absoluto
    · aba suspensa volta com o tempo certo, porque nada foi acumulado

  PRD §10.1 e design/README.md — "nunca de um timer local iniciado no navegador".
  ─────────────────────────────────────────────────────────────────────────────
*/

type Props = {
  /** Verdade do servidor no instante da resposta. */
  segundosRestantes: number;
  /** Prazo total da reserva, em segundos — define a escala da barra (RN-01). */
  prazoTotalSegundos: number;
  /** Para onde ir quando o tempo zerar. */
  hrefExpiracao: string;
};

const LIMITE_ALERTA = 120;
const LIMITE_CRITICO = 30;

export function ContadorRegressivo({
  segundosRestantes,
  prazoTotalSegundos,
  hrefExpiracao,
}: Props) {
  const router = useRouter();
  const [restante, setRestante] = useState(segundosRestantes);

  useEffect(() => {
    // A âncora: o instante, no relógio local, em que esta reserva expira.
    const ancora = Date.now() + segundosRestantes * 1000;

    const tick = () => {
      const segundos = Math.max(0, Math.round((ancora - Date.now()) / 1000));
      setRestante(segundos);
      return segundos;
    };

    tick();
    const id = setInterval(() => {
      if (tick() === 0) {
        clearInterval(id);
        router.push(hrefExpiracao);
      }
    }, 1000);

    return () => clearInterval(id);
  }, [segundosRestantes, hrefExpiracao, router]);

  const critico = restante <= LIMITE_CRITICO;
  const alerta = !critico && restante <= LIMITE_ALERTA;

  const corTexto = critico
    ? "text-countdown-erro-texto"
    : alerta
      ? "text-countdown-alerta-texto"
      : "text-papel";

  const corBarra = critico
    ? "bg-countdown-erro"
    : alerta
      ? "bg-countdown-alerta"
      : "bg-primaria";

  const proporcao = Math.min(100, (restante / prazoTotalSegundos) * 100);

  return (
    <div className="sticky top-3 z-20 rounded-botao bg-tinta px-[18px] pt-3.5 pb-4 text-papel shadow-countdown">
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-[14.5px] font-semibold text-desabilitado">
          {critico ? "Últimos segundos" : "Seu lugar está guardado"}
        </span>
        <span
          className={`font-mono text-[22px] font-bold tabular-nums ${corTexto} ${critico ? "animate-pulso" : ""}`}
          /* O leitor de tela não precisa ouvir cada segundo — só o minuto. */
          aria-live="off"
        >
          {formatarRelogio(restante)}
        </span>
      </div>
      <div
        className="mt-2.5 h-[5px] overflow-hidden rounded-full bg-trilha-escura"
        role="progressbar"
        aria-label="Tempo restante da reserva"
        aria-valuemin={0}
        aria-valuemax={prazoTotalSegundos}
        aria-valuenow={restante}
      >
        <div
          className={`h-full rounded-full transition-[width] duration-300 ease-linear ${corBarra}`}
          style={{ width: `${proporcao}%` }}
        />
      </div>
    </div>
  );
}
