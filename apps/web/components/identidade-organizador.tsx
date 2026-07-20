"use client";

import { useSessao } from "@/lib/sessao";

/**
 * O nome de quem está logado, no canto do header.
 *
 * Era a constante "Rafael M." desde o pacote de design. Vira dado de sessão
 * agora porque o painel passou a ser de quem entrou: um header dizendo um nome
 * e uma tela mostrando os eventos de outro seria a pior forma de errar isso.
 */
function iniciais(nome: string): string {
  const partes = nome.trim().split(/\s+/);
  const primeira = partes[0]?.[0] ?? "";
  const ultima = partes.length > 1 ? (partes[partes.length - 1][0] ?? "") : "";

  return (primeira + ultima).toUpperCase();
}

/** "Rafael Mendes" → "Rafael M." */
function abreviado(nome: string): string {
  const partes = nome.trim().split(/\s+/);

  return partes.length > 1
    ? `${partes[0]} ${partes[partes.length - 1][0]}.`
    : partes[0];
}

export function IdentidadeOrganizador() {
  const { usuario, carregando, sair } = useSessao();

  if (carregando || usuario === null) {
    // Sem placeholder de nome: escrever qualquer coisa aqui enquanto a sessão
    // carrega é exatamente o hábito que trouxe a constante.
    return <div className="ml-auto h-[34px]" />;
  }

  return (
    <div className="ml-auto flex items-center gap-2.5">
      <span className="text-sm text-creme">{abreviado(usuario.nome)}</span>
      <span className="grid size-[34px] place-items-center rounded-full bg-primaria text-[13px] font-bold text-off-white">
        {iniciais(usuario.nome)}
      </span>
      <button
        type="button"
        onClick={() => void sair()}
        className="text-[13px] font-semibold text-nav-inativa underline transition-colors hover:text-papel"
      >
        Sair
      </button>
    </div>
  );
}
