import Link from "next/link";
import { buscarEventos } from "@/lib/dados";
import { chipDeData, formatarDataEvento, formatarDinheiro } from "@/lib/formato";
import { Pill } from "@/components/pill";
import type { EventoResumo } from "@/lib/tipos";

/*
  Renderização dinâmica, não estática.
  A vitrine mostra disponibilidade que muda a cada reserva — pré-renderizar no
  build congelaria o estoque no instante da publicação. O PRD §6.4 permite até
  5s de defasagem, e é a própria API que os concede via Cache-Control.
*/
export const dynamic = "force-dynamic";


/* design/comprador/01-vitrine.html — GET /api/eventos */

export const metadata = {
  title: "lugar. — seu lugar, guardado",
};

function pillDoEvento(evento: EventoResumo) {
  switch (evento.situacao) {
    case "DISPONIVEL":
      return <Pill tom="sucesso">Disponível</Pill>;
    case "ULTIMOS":
      return <Pill tom="alerta">Últimos {evento.restantes}</Pill>;
    case "ESGOTADO":
      return <Pill tom="neutro">Esgotado</Pill>;
    case "EM_BREVE":
      return <Pill tom="neutro">Em breve</Pill>;
  }
}

function CartaoEvento({ evento }: { evento: EventoResumo }) {
  const { dia, mes } = chipDeData(evento.iniciaEm);
  const esgotado = evento.situacao === "ESGOTADO";

  const conteudo = (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="w-[52px] flex-none rounded-input border border-borda-card bg-papel py-[7px] text-center">
          <div className="font-display text-xl leading-none font-extrabold">
            {dia}
          </div>
          <div className="text-[11px] font-bold tracking-[1px] text-bronze">
            {mes}
          </div>
        </div>
        {pillDoEvento(evento)}
      </div>

      <div className="flex flex-col gap-1">
        <div className="font-display text-xl leading-[1.15] font-bold tracking-[-0.3px]">
          {evento.titulo}
        </div>
        <div className="text-sm text-texto-3">
          {evento.local} · {evento.cidade} — {formatarDataEvento(evento.iniciaEm)}
        </div>
      </div>

      <div className="mt-auto flex items-center justify-between border-t border-dashed border-borda-card pt-3">
        <span className="text-sm text-texto-3">
          {evento.precoMinimo
            ? `a partir de ${formatarDinheiro(evento.precoMinimo)}`
            : "—"}
        </span>
        <span
          className={`text-sm font-bold ${esgotado ? "text-texto-tenue" : "text-primaria-hover"}`}
        >
          {esgotado ? "—" : "Garantir lugar →"}
        </span>
      </div>
    </>
  );

  const classe =
    "flex flex-col gap-3.5 rounded-card border border-borda-card bg-white p-5";

  if (esgotado) {
    return <div className={`${classe} opacity-55`}>{conteudo}</div>;
  }

  return (
    <Link
      href={`/eventos/${evento.id}`}
      className={`${classe} text-tinta transition-all hover:-translate-y-0.5 hover:shadow-card-hover`}
    >
      {conteudo}
    </Link>
  );
}

export default async function Vitrine() {
  const eventos = await buscarEventos();

  return (
    <main className="mx-auto max-w-[1080px] animate-sobe px-6 pt-7 pb-24">
      <div className="py-[34px] pb-[38px]">
        <h1 className="m-0 font-display text-[clamp(40px,6vw,64px)] leading-[1.02] font-extrabold tracking-[-1.5px]">
          Seu lugar,
          <br />
          guardado<span className="text-primaria">.</span>
        </h1>
        <p className="mt-4 max-w-[46ch] text-[17px] leading-[1.55] text-pretty text-texto-2">
          Escolha o evento e reserve. A gente segura seu ingresso enquanto você
          finaliza o pagamento — sem risco de alguém levar seu lugar no meio do
          caminho.
        </p>
      </div>

      <div className="flex items-baseline justify-between border-t border-borda-secao pt-[18px] pb-3.5">
        <h2 className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
          Próximos eventos
        </h2>
        <span className="text-[13px] text-texto-4">
          disponibilidade em tempo real
        </span>
      </div>

      <div className="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-4">
        {eventos.map((evento) => (
          <CartaoEvento key={evento.id} evento={evento} />
        ))}
      </div>

      <p className="mt-11 text-center text-[13px] text-texto-tenue">
        Protótipo de demonstração — pagamentos em ambiente de testes.
      </p>
    </main>
  );
}
