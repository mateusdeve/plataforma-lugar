"use client";

import Link from "next/link";
import { Marca } from "./marca";
import { useSessao } from "@/lib/sessao";

/**
 * O header muda conforme quem está olhando.
 *
 * "Sou organizador" só aparece para quem tem o papel — mas isso é conveniência
 * de tela, não segurança: quem chamar `/api/organizador/...` sem o papel leva
 * 403 do Symfony de qualquer forma (ADR-004). Esconder o link evita oferecer
 * um caminho que termina em erro, e nada além disso.
 */
export function HeaderComprador() {
  const { usuario, carregando, sair, ehOrganizador, ehPortaria } = useSessao();

  return (
    <header className="mx-auto flex max-w-[1080px] flex-wrap items-center justify-between gap-3 px-6 pt-[22px] pb-1.5">
      <Marca href="/" />

      <nav className="flex items-center gap-4 text-sm font-semibold">
        {carregando ? (
          // Espaço reservado com a mesma altura, para o header não pular
          // quando a sessão é restaurada pelo cookie de refresh.
          <span className="h-5 w-24 animate-pulse rounded-full bg-borda-card" />
        ) : usuario ? (
          <>
            {ehOrganizador && (
              <Link href="/painel" className="text-texto-3 hover:text-primaria-hover">
                Painel
              </Link>
            )}
            {ehPortaria && (
              <Link
                href="/portaria/frontz-conf-2026"
                className="text-texto-3 hover:text-primaria-hover"
              >
                Portaria
              </Link>
            )}
            <span className="text-texto-4" title={usuario.email}>
              {usuario.nome.split(" ")[0]}
            </span>
            <button
              type="button"
              onClick={() => void sair()}
              className="text-texto-3 underline hover:text-primaria-hover"
            >
              Sair
            </button>
          </>
        ) : (
          <>
            <Link href="/entrar" className="text-texto-3 hover:text-primaria-hover">
              Entrar
            </Link>
            <Link
              href="/cadastro"
              className="rounded-full bg-tinta px-4 py-2 text-papel transition-colors hover:bg-primaria"
            >
              Criar conta
            </Link>
          </>
        )}
      </nav>
    </header>
  );
}
