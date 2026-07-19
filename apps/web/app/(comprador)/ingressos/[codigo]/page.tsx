import Link from "next/link";
import { notFound } from "next/navigation";
import { buscarIngresso } from "@/lib/dados";
import { formatarDataEvento } from "@/lib/formato";
import { QrIlustrativo } from "@/components/qr-ilustrativo";

/* design/comprador/05-confirmada.html — GET /api/ingressos/{codigo} */

export const metadata = { title: "Ingresso emitido — lugar." };

export default async function PaginaIngresso({
  params,
}: PageProps<"/ingressos/[codigo]">) {
  const { codigo } = await params;
  const ingresso = await buscarIngresso(codigo);
  if (!ingresso) notFound();

  return (
    <main className="mx-auto max-w-[560px] animate-sobe px-6 pt-10 pb-28">
      <div className="mb-6 text-center">
        <h1 className="m-0 font-display text-4xl font-extrabold tracking-[-0.8px]">
          É seu<span className="text-primaria">.</span>
        </h1>
        <p className="mt-2 text-[15.5px] text-texto-2">
          Pagamento aprovado. Também enviamos tudo para{" "}
          <strong>{ingresso.compradorEmail}</strong>.
        </p>
      </div>

      <article className="overflow-hidden rounded-bilhete bg-tinta text-papel shadow-bilhete">
        <div className="px-[26px] pt-6 pb-5">
          <div className="text-xs font-bold tracking-[2px] text-bronze uppercase">
            Ingresso · {ingresso.quantidade}{" "}
            {ingresso.quantidade === 1 ? "ingresso" : "ingressos"}
          </div>
          <h2 className="mt-1.5 font-display text-[26px] font-extrabold tracking-[-0.5px]">
            {ingresso.eventoTitulo}
          </h2>
          <div className="mt-1 text-[14.5px] text-creme">
            {formatarDataEvento(ingresso.eventoIniciaEm)} ·{" "}
            {ingresso.eventoLocal}
          </div>
        </div>

        {/* O picote: os dois semicírculos usam a cor do fundo da página. */}
        <div className="flex items-center gap-2.5 px-2.5">
          <span className="-ml-5 size-5 flex-none rounded-full bg-papel" />
          <span className="flex-1 border-t-2 border-dashed border-borda-tinta" />
          <span className="-mr-5 size-5 flex-none rounded-full bg-papel" />
        </div>

        <div className="flex flex-wrap items-center gap-5 px-[26px] pt-5 pb-6">
          <QrIlustrativo codigo={ingresso.codigo} />
          <div className="flex min-w-[180px] flex-1 flex-col gap-1.5">
            <span className="text-xs font-bold tracking-[2px] text-bronze uppercase">
              Código único
            </span>
            <span className="font-mono text-[22px] font-bold tracking-[1px]">
              {ingresso.codigo}
            </span>
            <span className="text-[13.5px] leading-[1.45] text-creme">
              {ingresso.loteNome} · apresente na entrada, impresso ou na tela.
            </span>
          </div>
        </div>
      </article>

      <p className="mt-[22px] text-center">
        <Link
          href="/"
          className="text-[14.5px] font-semibold text-primaria-hover hover:text-link-hover hover:underline"
        >
          ← Voltar aos eventos
        </Link>
      </p>
    </main>
  );
}
