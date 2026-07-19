import Link from "next/link";
import { notFound } from "next/navigation";
import { buscarEvento } from "@/lib/dados";
import { formatarDataEvento } from "@/lib/formato";
import { SeletorDeLotes } from "@/components/seletor-de-lotes";
import { TIPO_ERRO } from "@/lib/tipos";

/* Dinâmica pelo mesmo motivo da vitrine: o estoque por lote muda a cada
   reserva criada ou expirada. */
export const dynamic = "force-dynamic";


/*
  design/comprador/02-evento.html — GET /api/eventos/{id}
  design/comprador/03-evento-esgotou-409.html — mesmo componente, com o banner

  Os dois 409 do sistema têm tratamentos diferentes e é o campo `type` do
  problem+json que os separa (PRD §9):
    · estoque-insuficiente → banner aqui, o comprador continua na tela
    · reserva-expirada     → tela própria, em /reservas/{id}/expirada

  A distinção nunca é feita pela mensagem — só pelo `type`.
*/

export async function generateMetadata({
  params,
}: PageProps<"/eventos/[id]">) {
  const { id } = await params;
  const evento = await buscarEvento(id);
  return { title: evento ? `${evento.titulo} — lugar.` : "Evento — lugar." };
}

export default async function PaginaEvento({
  params,
  searchParams,
}: PageProps<"/eventos/[id]">) {
  const { id } = await params;
  const query = await searchParams;
  const evento = await buscarEvento(id);

  if (!evento) notFound();

  const houveConflitoDeEstoque =
    query.erro === "estoque-insuficiente" ||
    query.type === TIPO_ERRO.estoqueInsuficiente;

  const loteEsgotadoAgora =
    typeof query.lote === "string" ? query.lote : undefined;

  return (
    <main className="mx-auto max-w-[640px] animate-sobe px-6 pt-4 pb-28">
      <Link
        href="/"
        className="text-sm font-semibold text-primaria-hover hover:text-link-hover hover:underline"
      >
        ← Todos os eventos
      </Link>

      <h1 className="mt-[18px] mb-2.5 font-display text-[clamp(30px,5vw,42px)] leading-[1.05] font-extrabold tracking-[-1px]">
        {evento.titulo}
      </h1>

      <div className="flex flex-wrap gap-x-[18px] gap-y-2 text-[14.5px] font-medium text-texto-3">
        <span>{formatarDataEvento(evento.iniciaEm)}</span>
        <span aria-hidden>·</span>
        <span>
          {evento.local}, {evento.cidade}
        </span>
      </div>

      <p className="mt-[18px] mb-6 text-base leading-[1.6] text-pretty text-texto-2">
        {evento.descricao}
      </p>

      {houveConflitoDeEstoque && (
        <div
          role="alert"
          className="mb-[18px] rounded-[14px] border border-conflito-borda bg-conflito-bg px-[18px] py-4"
        >
          <div className="text-[15px] font-bold text-conflito-titulo">
            Esgotou enquanto você decidia
          </div>
          <div className="mt-1 text-[14.5px] leading-[1.5] text-conflito-texto">
            Acontece nos eventos bons. Alguém garantiu os últimos lugares deste
            lote há segundos. Se um novo lote abrir, ele aparece aqui na hora.
          </div>
        </div>
      )}

      <SeletorDeLotes evento={evento} loteEsgotadoAgora={loteEsgotadoAgora} />
    </main>
  );
}
