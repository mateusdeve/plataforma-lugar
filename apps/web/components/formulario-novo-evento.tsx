"use client";

import { useState } from "react";
import { Campo, CampoTexto } from "./campo";
import { Toast, useToast } from "./toast";

/*
  design/organizador/02-novo-evento.html

  RN-01: o prazo da reserva é configurável por evento, entre 5 e 30 minutos.
  As opções abaixo são os valores do design — a API valida o intervalo, não a
  tela, mas oferecer só valores válidos evita um erro que ninguém precisa ver.
*/
const PRAZOS = [5, 10, 15, 30] as const;

type LinhaLote = { chave: number; nome: string; preco: string; lugares: string };

let proximaChave = 0;
const novaLinha = (nome: string, preco = "", lugares = ""): LinhaLote => ({
  chave: proximaChave++,
  nome,
  preco,
  lugares,
});

export function FormularioNovoEvento() {
  const toast = useToast();
  const [prazo, setPrazo] = useState<number>(10);
  const [lotes, setLotes] = useState<LinhaLote[]>([
    novaLinha("1º lote", "180", "200"),
    novaLinha("2º lote", "220", "310"),
  ]);

  function atualizar(chave: number, campo: keyof LinhaLote, valor: string) {
    setLotes((atual) =>
      atual.map((l) => (l.chave === chave ? { ...l, [campo]: valor } : l)),
    );
  }

  function publicar(evento: React.FormEvent) {
    evento.preventDefault();
    // POST /api/eventos, depois POST /api/eventos/{id}/publicar (fase 6).
    toast.mostrar("Evento publicado — já aparece na vitrine.");
  }

  return (
    <form
      onSubmit={publicar}
      className="flex flex-col gap-4 rounded-card border border-borda-card bg-white p-[22px]"
    >
      <Campo
        rotulo="Título do evento"
        required
        placeholder="Ex.: Meetup de Engenharia — edição 12"
      />

      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
        <Campo rotulo="Local" required placeholder="Teatro B32, São Paulo" />
        <Campo rotulo="Data e hora" required placeholder="12/09/2026 · 09h" />
      </div>

      <CampoTexto
        rotulo="Descrição"
        rows={3}
        placeholder="Conte o que faz esse evento valer o sábado das pessoas."
      />

      <fieldset className="flex flex-col gap-1.5 border-0 p-0">
        <legend className="text-[13.5px] font-semibold text-texto-2">
          Prazo da reserva
        </legend>
        <div className="mt-1.5 flex flex-wrap items-center gap-2.5">
          {PRAZOS.map((minutos) => {
            const ativo = minutos === prazo;
            return (
              <button
                key={minutos}
                type="button"
                onClick={() => setPrazo(minutos)}
                aria-pressed={ativo}
                className={`rounded-full border-[1.5px] px-3.5 py-2 text-sm transition-colors ${
                  ativo
                    ? "border-primaria bg-conflito-bg font-bold text-primaria-hover"
                    : "border-borda-input text-texto-3 hover:border-borda-forte"
                }`}
              >
                {minutos} min
              </button>
            );
          })}
        </div>
        <span className="text-[13px] text-texto-4">
          Quanto tempo o comprador tem para pagar antes do lugar voltar ao
          estoque.
        </span>
      </fieldset>

      <div className="flex flex-col gap-2.5 border-t border-dashed border-borda-card pt-4">
        <span className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
          Lotes
        </span>

        {lotes.map((lote) => (
          <div
            key={lote.chave}
            className="grid grid-cols-[1.4fr_1fr_1fr_auto] items-center gap-2.5"
          >
            <input
              value={lote.nome}
              onChange={(e) => atualizar(lote.chave, "nome", e.target.value)}
              aria-label="Nome do lote"
              placeholder="Nome do lote"
              className="rounded-input border-[1.5px] border-borda-input bg-papel-input px-3.5 py-3 text-[14.5px] outline-none focus:border-primaria"
            />
            <input
              value={lote.preco}
              onChange={(e) => atualizar(lote.chave, "preco", e.target.value)}
              aria-label="Preço em reais"
              inputMode="numeric"
              placeholder="Preço (R$)"
              className="rounded-input border-[1.5px] border-borda-input bg-papel-input px-3.5 py-3 text-[14.5px] outline-none focus:border-primaria"
            />
            <input
              value={lote.lugares}
              onChange={(e) => atualizar(lote.chave, "lugares", e.target.value)}
              aria-label="Quantidade de lugares"
              inputMode="numeric"
              placeholder="Lugares"
              className="rounded-input border-[1.5px] border-borda-input bg-papel-input px-3.5 py-3 text-[14.5px] outline-none focus:border-primaria"
            />
            <button
              type="button"
              onClick={() =>
                setLotes((atual) => atual.filter((l) => l.chave !== lote.chave))
              }
              disabled={lotes.length === 1}
              aria-label={`Remover ${lote.nome || "lote"}`}
              className="size-[38px] rounded-input border-[1.5px] border-borda-input bg-white text-base text-texto-4 transition-colors hover:border-erro hover:text-erro disabled:opacity-40 disabled:hover:border-borda-input disabled:hover:text-texto-4"
            >
              ×
            </button>
          </div>
        ))}

        <button
          type="button"
          onClick={() => setLotes((atual) => [...atual, novaLinha("")])}
          className="w-fit text-sm font-bold text-primaria-hover hover:text-link-hover hover:underline"
        >
          + adicionar lote
        </button>
      </div>

      <button
        type="submit"
        className="mt-1 rounded-botao bg-primaria px-4 py-[17px] font-display text-[17px] font-bold text-off-white transition-colors hover:bg-primaria-hover"
      >
        Publicar evento
      </button>

      <Toast mensagem={toast.mensagem} aoFechar={toast.fechar} />
    </form>
  );
}
