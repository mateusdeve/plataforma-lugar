"use client";

import { useCallback } from "react";
import type { Comprador } from "@/lib/tipos";
import { Toast, useToast } from "./toast";

/*
  Exportação de compradores, montada no cliente a partir da lista já carregada.

  A API devolve no máximo 200 reservas por painel, então o CSV cobre o que a
  tela cobre — e não mais. Quando um evento passar disso, a exportação precisa
  virar uma rota própria: um arquivo silenciosamente truncado é pior que um
  botão que ainda não existe.
*/

const COLUNAS = ["nome", "email", "lote", "quantidade", "status"] as const;

function paraCsv(compradores: Comprador[]): string {
  /*
    As aspas não são enfeite: um nome com vírgula ("Souza, Ana") viraria duas
    colunas, e todas as linhas seguintes ficariam deslocadas. Aspas internas
    são dobradas, que é como o RFC 4180 as escapa.
  */
  const escapar = (valor: string | number) => `"${String(valor).replace(/"/g, '""')}"`;

  const linhas = compradores.map((c) =>
    [c.nome ?? "", c.email, c.loteNome, c.quantidade, c.status]
      .map(escapar)
      .join(","),
  );

  return [COLUNAS.join(","), ...linhas].join("\n");
}

export function ExportarCsv({ compradores }: { compradores: Comprador[] }) {
  const toast = useToast();
  const { mostrar } = toast;

  const exportar = useCallback(() => {
    const blob = new Blob([paraCsv(compradores)], {
      type: "text/csv;charset=utf-8",
    });
    const url = URL.createObjectURL(blob);

    const link = document.createElement("a");
    link.href = url;
    link.download = "compradores.csv";
    link.click();
    URL.revokeObjectURL(url);

    mostrar(`compradores.csv exportado — ${compradores.length} linhas.`);
  }, [compradores, mostrar]);

  return (
    <>
      <button
        type="button"
        onClick={exportar}
        className="rounded-input border-[1.5px] border-borda-forte bg-white px-3.5 py-2 text-[13.5px] font-bold text-tinta transition-colors hover:border-primaria hover:text-primaria-hover"
      >
        Exportar CSV
      </button>
      <Toast mensagem={toast.mensagem} aoFechar={toast.fechar} />
    </>
  );
}
