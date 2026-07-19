import Link from "next/link";
import { buscarReserva } from "@/lib/dados";

/*
  design/comprador/06-expirada.html

  Estado terminal EXPIRADA (PRD §4.3). Uma reserva expirada nunca é
  reaproveitada — o botão leva de volta ao evento para criar outra, não para
  "retomar" esta.

  O PRD §10.1 pede que a expiração seja tratada com dignidade: explicar o que
  houve e oferecer o próximo passo. A pessoa não fez nada errado; o sistema
  cumpriu o combinado e devolveu o lugar ao estoque.
*/

export const metadata = { title: "Reserva expirada — lugar." };

export default async function PaginaExpirada({
  params,
}: PageProps<"/reservas/[id]/expirada">) {
  const { id } = await params;
  const reserva = await buscarReserva(id);
  const hrefEvento = reserva ? `/eventos/${reserva.eventoId}` : "/";

  return (
    <main className="mx-auto max-w-[520px] animate-sobe px-6 pt-16 pb-28 text-center">
      <div className="font-mono text-[56px] font-bold tracking-[2px] text-desabilitado">
        00:00
      </div>

      <h1 className="mt-3.5 mb-3 font-display text-[34px] font-extrabold tracking-[-0.8px]">
        O tempo acabou — tudo bem.
      </h1>

      <p className="m-0 text-base leading-[1.6] text-pretty text-texto-2">
        Guardamos seu lugar por 10 minutos, mas o pagamento não chegou a tempo.
        O ingresso voltou para o estoque, como combinado. Se ainda houver
        lugares, é só recomeçar — leva menos de um minuto.
      </p>

      <Link
        href={hrefEvento}
        className="mt-7 inline-block rounded-botao bg-primaria px-8 py-4 font-display text-[17px] font-bold text-off-white transition-colors hover:bg-primaria-hover"
      >
        Ver disponibilidade agora
      </Link>

      <p className="mt-4">
        <Link
          href="/"
          className="text-sm font-semibold text-texto-4 underline hover:text-primaria-hover"
        >
          voltar aos eventos
        </Link>
      </p>
    </main>
  );
}
