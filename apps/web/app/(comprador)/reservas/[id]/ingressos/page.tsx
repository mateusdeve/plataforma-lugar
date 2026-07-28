"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { buscarIngressosDaReserva } from "@/lib/dados";
import { formatarDataEvento } from "@/lib/formato";
import { QrDoIngresso } from "@/components/qr-do-ingresso";
import type { Ingresso } from "@/lib/tipos";

/*
  design/comprador/05-confirmada.html — GET /api/reservas/{id}/ingressos

  A rota é da RESERVA, e não de um código. RN-08 emite um ingresso por unidade:
  quem comprou quatro sai daqui com quatro bilhetes, cada um com seu código,
  para repassar um por pessoa. Uma tela por código esconderia os outros três.
*/

function Bilhete({ ingresso }: { ingresso: Ingresso }) {
  return (
    <article className="overflow-hidden rounded-bilhete bg-tinta text-papel shadow-bilhete">
      <div className="px-[26px] pt-6 pb-5">
        <div className="text-xs font-bold tracking-[2px] text-bronze uppercase">
          Ingresso
        </div>
        <h2 className="mt-1.5 font-display text-[26px] font-extrabold tracking-[-0.5px]">
          {ingresso.eventoTitulo}
        </h2>
        <div className="mt-1 text-[14.5px] text-creme">
          {formatarDataEvento(ingresso.eventoIniciaEm)} · {ingresso.eventoLocal}
        </div>
      </div>

      {/* O picote: os dois semicírculos usam a cor do fundo da página. */}
      <div className="flex items-center gap-2.5 px-2.5">
        <span className="-ml-5 size-5 flex-none rounded-full bg-papel" />
        <span className="flex-1 border-t-2 border-dashed border-borda-tinta" />
        <span className="-mr-5 size-5 flex-none rounded-full bg-papel" />
      </div>

      <div className="flex flex-wrap items-center gap-5 px-[26px] pt-5 pb-6">
        <QrDoIngresso codigo={ingresso.codigo} />
        <div className="flex min-w-[180px] flex-1 flex-col gap-1.5">
          <span className="text-xs font-bold tracking-[2px] text-bronze uppercase">
            Código único
          </span>
          <span className="font-mono text-[22px] font-bold tracking-[1px]">
            {ingresso.codigo}
          </span>
          <span className="text-[13.5px] leading-[1.45] text-creme">
            {ingresso.loteNome} · vale UMA entrada. Apresente impresso ou na tela.
          </span>
        </div>
      </div>
    </article>
  );
}

export default function PaginaIngressos({
  params,
}: PageProps<"/reservas/[id]/ingressos">) {
  const { id } = use(params);

  const [ingressos, setIngressos] = useState<Ingresso[] | null>(null);
  const [falhou, setFalhou] = useState(false);

  useEffect(() => {
    let atual = true;

    buscarIngressosDaReserva(id)
      .then((itens) => {
        if (atual) setIngressos(itens);
      })
      .catch(() => {
        if (atual) setFalhou(true);
      });

    return () => {
      atual = false;
    };
  }, [id]);

  if (falhou) {
    return (
      <main className="mx-auto max-w-[560px] px-6 pt-20 pb-28 text-center">
        <h1 className="font-display text-[26px] font-extrabold">
          Não foi possível carregar seus ingressos
        </h1>
        <p className="mt-2.5 text-[15px] text-texto-3">
          Eles estão emitidos e também foram para o seu e-mail. Tente recarregar
          esta página.
        </p>
      </main>
    );
  }

  if (ingressos === null) {
    return (
      <main aria-busy="true" className="mx-auto max-w-[560px] px-6 pt-10 pb-28">
        <div className="h-[220px] animate-pulse rounded-bilhete bg-neutro-bg" />
        <span className="sr-only">Carregando seus ingressos…</span>
      </main>
    );
  }

  const primeiro = ingressos[0];

  return (
    <main className="mx-auto max-w-[560px] animate-sobe px-6 pt-10 pb-28">
      <div className="mb-6 text-center">
        <h1 className="m-0 font-display text-4xl font-extrabold tracking-[-0.8px]">
          É seu<span className="text-primaria">.</span>
        </h1>
        {primeiro !== undefined && (
          <p className="mt-2 text-[15.5px] text-texto-2">
            Pagamento aprovado. Também enviamos tudo para{" "}
            <strong>{primeiro.compradorEmail}</strong>.
          </p>
        )}
        {ingressos.length > 1 && (
          <p className="mt-1 text-[14px] text-texto-4">
            {ingressos.length} ingressos — um código por pessoa.
          </p>
        )}
      </div>

      <div className="flex flex-col gap-4">
        {ingressos.map((ingresso) => (
          <Bilhete key={ingresso.codigo} ingresso={ingresso} />
        ))}
      </div>

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
