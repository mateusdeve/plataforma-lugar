import Link from "next/link";
import { notFound } from "next/navigation";
import { buscarEvento, buscarReserva } from "@/lib/dados";
import { formatarDataEvento, formatarDinheiro } from "@/lib/formato";
import { ContadorRegressivo } from "@/components/contador-regressivo";
import { FormularioPagamento } from "@/components/formulario-pagamento";

/* design/comprador/04-checkout.html — GET /api/reservas/{id} + POST checkout */

export const metadata = { title: "Checkout — lugar." };

export default async function PaginaCheckout({
  params,
}: PageProps<"/checkout/[reservaId]">) {
  const { reservaId } = await params;
  const reserva = await buscarReserva(reservaId);
  if (!reserva) notFound();

  const evento = await buscarEvento(reserva.eventoId);
  if (!evento) notFound();

  const lote = evento.lotes.find((l) => l.id === reserva.loteId);

  return (
    <main className="mx-auto max-w-[640px] animate-sobe px-6 pt-2 pb-28">
      {/*
        O contador recebe a verdade do servidor. Ver o comentário longo em
        components/contador-regressivo.tsx — é a regra que mais se quebra
        sozinha se alguém "simplificar" depois.
      */}
      <ContadorRegressivo
        segundosRestantes={reserva.segundosRestantes}
        prazoTotalSegundos={evento.prazoReservaMinutos * 60}
        hrefExpiracao={`/reservas/${reserva.id}/expirada`}
      />

      <section
        aria-label="Resumo da reserva"
        className="mt-[18px] rounded-card border border-borda-card bg-white p-5"
      >
        <h2 className="mb-3 text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
          Resumo
        </h2>
        <div className="mb-1.5 flex justify-between gap-3 text-[15px]">
          <span className="font-bold">{evento.titulo}</span>
          <span className="whitespace-nowrap text-texto-3">
            {formatarDataEvento(evento.iniciaEm)}
          </span>
        </div>
        <div className="flex justify-between gap-3 text-[14.5px] text-texto-3">
          <span>{lote?.nome}</span>
          <span>
            {reserva.quantidade} × {lote && formatarDinheiro(lote.preco)}
          </span>
        </div>
        <div className="mt-3.5 flex justify-between border-t border-dashed border-borda-card pt-3 text-base">
          <span className="font-bold">Total</span>
          <span className="font-mono font-bold tabular-nums">
            {formatarDinheiro(reserva.total)}
          </span>
        </div>
      </section>

      <FormularioPagamento
        total={formatarDinheiro(reserva.total)}
        codigoIngresso="LGR-8F3K-92QX"
      />

      <p className="mt-[18px] text-center">
        <Link
          href={`/eventos/${evento.id}`}
          className="text-sm font-semibold text-texto-4 underline hover:text-primaria-hover"
        >
          Desistir e liberar meu lugar
        </Link>
      </p>
    </main>
  );
}
