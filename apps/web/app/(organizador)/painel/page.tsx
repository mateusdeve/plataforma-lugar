"use client";

import Link from "next/link";
import { useCallback } from "react";
import { buscarMeusEventos } from "@/lib/dados";
import { formatarDataEvento, formatarDinheiro } from "@/lib/formato";
import { useDoOrganizador } from "@/lib/organizador";
import { AvisoDoOrganizador } from "@/components/aviso-do-organizador";
import { Pill } from "@/components/pill";
import type { EventoDoOrganizador, EventoStatus } from "@/lib/tipos";

/*
  GET /api/organizador/eventos — a porta de entrada do painel.

  Esta lista é o vínculo do ADR-004 visível a olho nu: Rafael abre e vê os
  eventos dele. Não porque a tela filtra, mas porque a API monta a lista a
  partir do token e não tem como devolver outra coisa.
*/

function pillDoStatus(status: EventoStatus) {
  switch (status) {
    case "PUBLICADO":
      return <Pill tom="sucesso">Publicado</Pill>;
    case "RASCUNHO":
      return <Pill tom="alerta">Rascunho</Pill>;
    case "CANCELADO":
      return <Pill tom="neutro">Cancelado</Pill>;
  }
}

function CartaoDeEvento({ evento }: { evento: EventoDoOrganizador }) {
  const ocupacao =
    evento.capacidade > 0
      ? Math.round((evento.vendidos / evento.capacidade) * 100)
      : 0;

  return (
    <Link
      href={`/painel/eventos/${evento.id}`}
      className="block rounded-card border border-borda-card bg-white p-5 transition-colors hover:border-primaria"
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2.5">
        <h2 className="font-display text-[19px] font-extrabold tracking-[-0.3px]">
          {evento.titulo}
        </h2>
        {pillDoStatus(evento.status)}
      </div>

      <p className="mt-1 text-[13.5px] text-texto-3">
        {formatarDataEvento(evento.iniciaEm)} · {evento.local}, {evento.cidade}
      </p>

      <div className="mt-4 flex items-baseline justify-between text-[14px]">
        <span className="text-texto-3">
          <strong className="text-tinta">{evento.vendidos}</strong> /{" "}
          {evento.capacidade} vendidos
        </span>
        <span className="font-mono font-bold tabular-nums">
          {formatarDinheiro(evento.receita)}
        </span>
      </div>

      <div className="mt-2 h-2 rounded-full bg-trilha">
        <div
          className="h-full rounded-full bg-primaria"
          style={{ width: `${ocupacao}%` }}
        />
      </div>
    </Link>
  );
}

export default function PaginaMeusEventos() {
  const estado = useDoOrganizador(
    "meus-eventos",
    useCallback(() => buscarMeusEventos(), []),
  );

  if (estado.situacao !== "pronto") {
    return <AvisoDoOrganizador estado={estado} />;
  }

  const eventos = estado.dados;

  return (
    <main className="mx-auto max-w-[1120px] animate-sobe px-6 pt-[26px] pb-24">
      <h1 className="mb-5 font-display text-[30px] font-extrabold tracking-[-0.7px]">
        Meus eventos
      </h1>

      {eventos.length === 0 ? (
        <div className="rounded-card border border-dashed border-borda-card bg-white px-6 py-14 text-center">
          <p className="text-[15px] text-texto-3">
            Você ainda não criou nenhum evento.
          </p>
          <Link
            href="/painel/eventos/novo"
            className="mt-5 inline-block rounded-input bg-primaria px-5 py-2.5 text-[15px] font-bold text-off-white transition-colors hover:bg-primaria-hover"
          >
            Criar o primeiro
          </Link>
        </div>
      ) : (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-3.5">
          {eventos.map((evento) => (
            <CartaoDeEvento key={evento.id} evento={evento} />
          ))}
        </div>
      )}
    </main>
  );
}
