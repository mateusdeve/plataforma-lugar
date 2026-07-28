"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ErroDaApi } from "@/lib/api";
import { criarReserva } from "@/lib/dados";
import { useSessao } from "@/lib/sessao";
import type { EventoDetalhe, Lote } from "@/lib/tipos";
import { formatarDinheiro } from "@/lib/formato";
import { Botao } from "./botao";
import { Campo } from "./campo";

/** RN-04: no máximo 6 ingressos por reserva. */
const MAXIMO_POR_RESERVA = 6;

function rotuloDaSituacao(lote: Lote): { texto: string; cor: string } {
  switch (lote.situacao) {
    case "DISPONIVEL":
      return {
        texto: `${lote.disponivel} ${lote.disponivel === 1 ? "disponível" : "disponíveis"}`,
        cor: "text-sucesso",
      };
    case "ESGOTADO":
      return { texto: "Esgotado", cor: "text-texto-4" };
    case "EM_BREVE": {
      const abre = new Date(lote.vendasIniciamEm);
      const meses = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];
      return {
        texto: `abre ${abre.getDate()} ${meses[abre.getMonth()]}`,
        cor: "text-texto-4",
      };
    }
    case "ENCERRADO":
      return { texto: "Encerrado", cor: "text-texto-4" };
  }
}

export function SeletorDeLotes({
  evento,
  loteEsgotadoAgora,
}: {
  evento: EventoDetalhe;
  /**
   * Id do lote que a API acabou de recusar por estoque (409
   * estoque-insuficiente). Ele passa a exibir "Esgotado" porque é isso que o
   * servidor agora reporta — a tela não mente sobre o que ainda existe.
   */
  loteEsgotadoAgora?: string;
}) {
  const router = useRouter();
  const { usuario } = useSessao();

  const lotes = evento.lotes.map((lote) =>
    lote.id === loteEsgotadoAgora
      ? { ...lote, situacao: "ESGOTADO" as const, disponivel: 0 }
      : lote,
  );

  const selecionaveis = lotes.filter((l) => l.situacao === "DISPONIVEL");
  const [loteId, setLoteId] = useState(selecionaveis[0]?.id ?? null);
  const [quantidade, setQuantidade] = useState(selecionaveis.length ? 2 : 1);

  const selecionado = lotes.find((l) => l.id === loteId) ?? null;

  // RN-03 e RN-04: o teto é o menor entre o limite por pessoa e o estoque real.
  const teto = selecionado
    ? Math.min(MAXIMO_POR_RESERVA, selecionado.disponivel)
    : 0;
  const qtd = Math.min(quantidade, Math.max(1, teto));

  const total = selecionado
    ? formatarDinheiro({
        centavos: selecionado.preco.centavos * qtd,
        moeda: "BRL",
      })
    : null;

  // Convidado informa o e-mail AQUI, não no pagamento: é a reserva que
  // retém estoque em nome de alguém (RN-05 conta por e-mail), e é para ele
  // que o ingresso vai. Logado, a API usa o e-mail da conta e ignora o campo.
  const [email, setEmail] = useState("");
  const [enviando, setEnviando] = useState(false);
  const [erro, setErro] = useState<string | null>(null);

  const emailValido = usuario !== null || email.includes("@");
  const podeReservar = selecionado !== null && emailValido && !enviando;

  async function reservar() {
    if (!podeReservar || selecionado === null) return;

    setEnviando(true);
    setErro(null);

    try {
      const reserva = await criarReserva({
        loteId: selecionado.id,
        quantidade: qtd,
        compradorEmail: usuario?.email ?? email.trim(),
        // Um UUID por CLIQUE: a retentativa em rede móvel repete a chave e
        // recebe a MESMA reserva de volta, em vez de criar uma segunda
        // (PRD §6.2).
        chaveDeIdempotencia: crypto.randomUUID(),
      });

      router.push(`/checkout/${reserva.id}`);
    } catch (falha) {
      setEnviando(false);

      if (falha instanceof ErroDaApi) {
        // O 409 de estoque volta para ESTA tela, com o lote marcado como
        // esgotado via query string — o servidor acabou de dizer que ele
        // não existe mais, e a tela não mente (PRD §9).
        if (falha.chave === "estoque-insuficiente") {
          router.replace(
            `/eventos/${evento.id}?erro=estoque-insuficiente&lote=${selecionado.id}`,
          );
          router.refresh();
          return;
        }

        setErro(falha.message);
        return;
      }

      setErro("Não foi possível guardar seu lugar. Tente de novo.");
    }
  }

  return (
    <>
      <div className="mb-2.5 flex items-baseline justify-between">
        <span className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
          Lotes
        </span>
        <span className="text-[12.5px] text-texto-4">atualizado agora</span>
      </div>

      <div className="flex flex-col gap-2.5">
        {lotes.map((lote) => {
          const disponivel = lote.situacao === "DISPONIVEL";
          const ativo = lote.id === loteId;
          const rotulo = rotuloDaSituacao(lote);

          return (
            <button
              key={lote.id}
              type="button"
              disabled={!disponivel}
              onClick={() => {
                setLoteId(lote.id);
                setQuantidade((q) => Math.min(q, Math.min(MAXIMO_POR_RESERVA, lote.disponivel)));
              }}
              aria-pressed={ativo}
              className={`flex items-center gap-3.5 rounded-[14px] border-[1.5px] px-[18px] py-4 text-left transition-colors ${
                ativo
                  ? "border-primaria bg-white"
                  : "border-borda-card bg-papel-input"
              } ${disponivel ? "cursor-pointer" : "cursor-default opacity-60"}`}
            >
              <span
                className={`size-5 flex-none rounded-full border-2 ${
                  ativo
                    ? "border-primaria bg-primaria shadow-[inset_0_0_0_3.5px_#fff]"
                    : "border-desabilitado bg-white shadow-[inset_0_0_0_3.5px_#fff]"
                }`}
              />
              <span className="flex min-w-0 flex-col gap-px">
                <span className="text-[15.5px] font-bold">{lote.nome}</span>
                <span className="text-sm text-texto-3">
                  {formatarDinheiro(lote.preco)} por ingresso
                </span>
              </span>
              <span
                className={`ml-auto text-right text-[13.5px] font-bold whitespace-nowrap ${rotulo.cor}`}
              >
                {rotulo.texto}
              </span>
            </button>
          );
        })}
      </div>

      <div className="mt-6 flex items-center justify-between">
        <div className="flex flex-col gap-0.5">
          <span className="text-[15.5px] font-bold">Quantidade</span>
          <span className="text-[13px] text-texto-4">
            máx. {MAXIMO_POR_RESERVA} por pessoa
          </span>
        </div>
        <div className="flex items-center gap-3.5">
          <button
            type="button"
            onClick={() => setQuantidade((q) => Math.max(1, q - 1))}
            disabled={!selecionado || qtd <= 1}
            aria-label="Diminuir quantidade"
            className="size-[46px] rounded-full border-[1.5px] border-borda-forte bg-white text-[22px] leading-none disabled:opacity-40"
          >
            −
          </button>
          <span
            className="w-7 text-center font-mono text-[22px] font-semibold tabular-nums"
            aria-live="polite"
          >
            {selecionado ? qtd : 0}
          </span>
          <button
            type="button"
            onClick={() => setQuantidade((q) => Math.min(teto, q + 1))}
            disabled={!selecionado || qtd >= teto}
            aria-label="Aumentar quantidade"
            className="size-[46px] rounded-full border-[1.5px] border-borda-forte bg-white text-[22px] leading-none disabled:opacity-40"
          >
            +
          </button>
        </div>
      </div>

      {usuario === null && selecionado !== null && (
        <div className="mt-5">
          <Campo
            rotulo="Seu e-mail — a reserva e o ingresso ficam nele"
            type="email"
            required
            autoComplete="email"
            placeholder="ana@email.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </div>
      )}

      {erro !== null && (
        <p role="alert" className="mt-4 mb-0 text-[14px] font-semibold text-primaria">
          {erro}
        </p>
      )}

      <Botao
        className="mt-6"
        disabled={!podeReservar}
        onClick={reservar}
      >
        {enviando
          ? "Guardando…"
          : selecionado
            ? `Guardar meu lugar — ${total}`
            : "Sem lugares neste momento"}
      </Botao>

      <p className="mt-3 text-center text-[13.5px] text-texto-4">
        Sua reserva fica guardada por{" "}
        <strong className="text-texto-2">
          {evento.prazoReservaMinutos} minutos
        </strong>{" "}
        enquanto você paga.
      </p>
    </>
  );
}
