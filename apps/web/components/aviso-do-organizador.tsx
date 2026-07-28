"use client";

import Link from "next/link";
import type { EstadoSemDados } from "@/lib/organizador";

/*
  A tela que aparece quando não há painel para mostrar.

  Cada situação ganha um texto próprio de propósito. "Algo deu errado" é o
  equivalente de interface a um `catch` vazio: verdadeiro, inútil, e a pessoa
  não sabe se recarrega, se entra de novo ou se pediu o endereço errado.
*/

type Texto = {
  titulo: string;
  corpo: string;
  acao?: { href: string; rotulo: string };
};

const TEXTOS: Record<
  Exclude<EstadoSemDados["situacao"], "carregando" | "erro">,
  Texto
> = {
  "sem-sessao": {
    // Serve ao painel e à porta: o token vive em memória, então "nesta aba" é
    // literal — abrir a portaria numa aba nova exige entrar de novo.
    titulo: "Entre para continuar",
    corpo: "Sua sessão terminou ou você ainda não entrou nesta aba.",
    acao: { href: "/entrar", rotulo: "Entrar" },
  },
  "sem-papel": {
    titulo: "Esta área não é da sua conta",
    corpo:
      "Seu perfil não tem o papel necessário. O painel é de quem organiza; a porta, de quem está escalado nela.",
    acao: { href: "/", rotulo: "Ver eventos" },
  },
  negado: {
    titulo: "Este evento não é seu",
    corpo:
      "Ele existe, e você não tem vínculo com ele — ter o papel não basta. O painel é do organizador dono; a porta, de quem está escalado nela (ADR-004).",
    acao: { href: "/painel", rotulo: "Meus eventos" },
  },
  ausente: {
    titulo: "Evento não encontrado",
    corpo: "O endereço pode estar errado, ou o evento foi removido.",
    acao: { href: "/painel", rotulo: "Meus eventos" },
  },
};

export function AvisoDoOrganizador({ estado }: { estado: EstadoSemDados }) {
  if (estado.situacao === "carregando") {
    return (
      <main
        aria-busy="true"
        className="mx-auto max-w-[1120px] px-6 pt-[26px] pb-24"
      >
        <div className="h-9 w-72 animate-pulse rounded-input bg-trilha" />
        <div className="mt-5 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
          {[0, 1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-[120px] animate-pulse rounded-card bg-trilha"
            />
          ))}
        </div>
        <span className="sr-only">Carregando o painel…</span>
      </main>
    );
  }

  const texto: Texto =
    estado.situacao === "erro"
      ? { titulo: "Não foi possível carregar", corpo: estado.mensagem }
      : TEXTOS[estado.situacao];

  return (
    <main className="mx-auto max-w-[560px] animate-sobe px-6 pt-20 pb-24 text-center">
      <h1 className="font-display text-[26px] font-extrabold tracking-[-0.5px]">
        {texto.titulo}
      </h1>
      <p className="mt-2.5 text-[15px] text-texto-3">{texto.corpo}</p>
      {texto.acao && (
        <Link
          href={texto.acao.href}
          className="mt-6 inline-block rounded-input bg-primaria px-5 py-2.5 text-[15px] font-bold text-off-white transition-colors hover:bg-primaria-hover"
        >
          {texto.acao.rotulo}
        </Link>
      )}
    </main>
  );
}
