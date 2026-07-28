"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ErroDaApi } from "@/lib/api";
import { criarEvento, publicarEvento } from "@/lib/dados";
import { Campo, CampoTexto } from "./campo";

/*
  design/organizador/02-novo-evento.html

  RN-01: o prazo da reserva é configurável por evento, entre 5 e 30 minutos.
  As opções abaixo são os valores do design — a API valida o intervalo, não a
  tela, mas oferecer só valores válidos evita um erro que ninguém precisa ver.

  O botão faz DUAS chamadas: POST /api/eventos (nasce RASCUNHO) e depois
  POST /api/eventos/{id}/publicar. Se a segunda falhar, o rascunho existe e a
  mensagem diz isso — sumir com o trabalho da pessoa porque a publicação
  falhou seria o pior dos desfechos.
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

/** "180" → 18000 · "180,50" → 18050. Dinheiro vira inteiro AQUI, uma vez. */
function paraCentavos(reais: string): number | null {
  const numero = Number(reais.trim().replace(/\./g, "").replace(",", "."));
  if (!Number.isFinite(numero) || numero < 0) return null;
  return Math.round(numero * 100);
}

export function FormularioNovoEvento() {
  const router = useRouter();

  const [titulo, setTitulo] = useState("");
  const [local, setLocal] = useState("");
  const [cidade, setCidade] = useState("");
  const [dataHora, setDataHora] = useState("");
  const [descricao, setDescricao] = useState("");
  const [prazo, setPrazo] = useState<number>(10);
  const [lotes, setLotes] = useState<LinhaLote[]>([
    novaLinha("1º lote", "180", "200"),
    novaLinha("2º lote", "220", "310"),
  ]);
  const [erro, setErro] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  function atualizar(chave: number, campo: keyof LinhaLote, valor: string) {
    setLotes((atual) =>
      atual.map((l) => (l.chave === chave ? { ...l, [campo]: valor } : l)),
    );
  }

  async function publicar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;

    const lotesValidos = lotes.map((l) => ({
      nome: l.nome.trim(),
      precoCentavos: paraCentavos(l.preco),
      quantidade: Number.parseInt(l.lugares, 10),
    }));

    const invalido = lotesValidos.find(
      (l) =>
        l.nome === "" ||
        l.precoCentavos === null ||
        !Number.isInteger(l.quantidade) ||
        l.quantidade < 1,
    );

    if (invalido !== undefined) {
      setErro("Cada lote precisa de nome, preço e uma quantidade de lugares.");
      return;
    }

    setEnviando(true);
    setErro(null);

    let id: string | null = null;

    try {
      const criado = await criarEvento({
        titulo: titulo.trim(),
        local: local.trim(),
        cidade: cidade.trim(),
        // datetime-local produz "2026-09-12T09:00", sem fuso: o instante é
        // interpretado no fuso do navegador, que é onde o evento acontece.
        iniciaEm: new Date(dataHora).toISOString(),
        descricao: descricao.trim(),
        prazoReservaMinutos: prazo,
        lotes: lotesValidos.map((l) => ({
          nome: l.nome,
          precoCentavos: l.precoCentavos ?? 0,
          quantidade: l.quantidade,
        })),
      });
      id = criado.id;

      await publicarEvento(id);
      router.push(`/painel/eventos/${id}`);
    } catch (e) {
      setEnviando(false);
      const mensagem = e instanceof ErroDaApi ? e.message : "Não foi possível salvar. Tente de novo.";
      setErro(
        id === null
          ? mensagem
          : `O evento foi salvo como rascunho, mas a publicação falhou: ${mensagem}`,
      );
    }
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
        value={titulo}
        onChange={(e) => setTitulo(e.target.value)}
      />

      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
        <Campo
          rotulo="Local"
          required
          placeholder="Teatro B32"
          value={local}
          onChange={(e) => setLocal(e.target.value)}
        />
        <Campo
          rotulo="Cidade"
          required
          placeholder="São Paulo"
          value={cidade}
          onChange={(e) => setCidade(e.target.value)}
        />
        <Campo
          rotulo="Data e hora"
          required
          type="datetime-local"
          value={dataHora}
          onChange={(e) => setDataHora(e.target.value)}
        />
      </div>

      <CampoTexto
        rotulo="Descrição"
        rows={3}
        placeholder="Conte o que faz esse evento valer o sábado das pessoas."
        value={descricao}
        onChange={(e) => setDescricao(e.target.value)}
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

      {erro !== null && (
        <p className="m-0 rounded-input border border-erro/40 bg-conflito-bg px-3.5 py-3 text-[14px] text-erro">
          {erro}
        </p>
      )}

      <button
        type="submit"
        disabled={enviando}
        className="mt-1 rounded-botao bg-primaria px-4 py-[17px] font-display text-[17px] font-bold text-off-white transition-colors hover:bg-primaria-hover disabled:opacity-60"
      >
        {enviando ? "Publicando…" : "Publicar evento"}
      </button>
    </form>
  );
}
