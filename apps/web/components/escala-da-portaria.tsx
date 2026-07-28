"use client";

import { useCallback, useEffect, useState } from "react";
import { ErroDaApi } from "@/lib/api";
import { buscarOperadores, escalarOperador, retirarOperador } from "@/lib/dados";
import type { Operador } from "@/lib/tipos";
import { Campo } from "./campo";

/*
  Fase 6.4 — quem valida ingresso na porta DESTE evento.

  A tela não decide permissão nenhuma: escalar concede o papel de portaria e
  grava o vínculo no servidor (tabela evento_operador), e é o PortariaVoter
  que abre ou fecha a porta a cada leitura. Aqui só se vê e edita a lista.

  O e-mail precisa ter conta. O 404 da API (type=operador-desconhecido) vira a
  mensagem que explica o que fazer — pedir para a pessoa se cadastrar — em vez
  de um "erro" que faria o organizador tentar de novo o que nunca vai passar.
*/
export function EscalaDaPortaria({ eventoId }: { eventoId: string }) {
  const [operadores, setOperadores] = useState<Operador[] | null>(null);
  const [email, setEmail] = useState("");
  const [erro, setErro] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    let atual = true;

    buscarOperadores(eventoId)
      .then((itens) => {
        if (atual) setOperadores(itens);
      })
      .catch(() => {
        // O painel em volta já provou a posse (o 403 dele tem tela própria);
        // uma falha aqui é rede ou o access token vencido, e a lista vazia
        // com o formulário funcionando é mais útil que a seção sumir.
        if (atual) setOperadores([]);
      });

    return () => {
      atual = false;
    };
  }, [eventoId]);

  const escalar = useCallback(
    async (evento: React.FormEvent) => {
      evento.preventDefault();
      if (enviando || !email.includes("@")) return;

      setEnviando(true);
      setErro(null);

      try {
        const operador = await escalarOperador(eventoId, email.trim());
        setOperadores((atual) => {
          const lista = atual ?? [];
          // Escalar de novo é idempotente na API; a lista segue o mesmo.
          return lista.some((o) => o.id === operador.id)
            ? lista
            : [...lista, operador];
        });
        setEmail("");
      } catch (e) {
        setErro(
          e instanceof ErroDaApi
            ? e.message
            : "Não foi possível escalar. Tente de novo.",
        );
      } finally {
        setEnviando(false);
      }
    },
    [email, enviando, eventoId],
  );

  const retirar = useCallback(
    async (operador: Operador) => {
      setErro(null);

      try {
        await retirarOperador(eventoId, operador.id);
        setOperadores((atual) =>
          (atual ?? []).filter((o) => o.id !== operador.id),
        );
      } catch {
        setErro(`Não foi possível retirar ${operador.email}. Tente de novo.`);
      }
    },
    [eventoId],
  );

  return (
    <section className="rounded-card border border-borda-card bg-white p-5">
      <h2 className="mb-1 text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
        Escala da portaria
      </h2>
      <p className="mb-3.5 text-[13.5px] text-texto-4">
        Quem pode validar ingresso na porta deste evento — e só deste.
      </p>

      {operadores === null ? (
        <p className="py-4 text-[14.5px] text-texto-4">Carregando…</p>
      ) : operadores.length === 0 ? (
        <p className="py-4 text-[14.5px] text-texto-4">
          Ninguém escalado ainda. Você mesmo valida a própria porta sem
          precisar se escalar.
        </p>
      ) : (
        <div className="mb-3.5 flex flex-col">
          {operadores.map((operador, i) => (
            <div
              key={operador.id}
              className={`flex items-center justify-between gap-2.5 py-[11px] text-[14.5px] ${
                i === operadores.length - 1 ? "" : "border-b border-trilha"
              }`}
            >
              <div className="min-w-0">
                <div className="font-semibold">{operador.nome}</div>
                <div className="truncate text-[13px] text-texto-4">
                  {operador.email}
                </div>
              </div>
              <button
                type="button"
                onClick={() => retirar(operador)}
                className="shrink-0 text-[13.5px] font-bold text-texto-4 transition-colors hover:text-erro"
              >
                retirar
              </button>
            </div>
          ))}
        </div>
      )}

      <form onSubmit={escalar} className="flex items-end gap-2.5">
        <Campo
          rotulo="E-mail de quem trabalha na porta"
          type="email"
          placeholder="portaria@email.com"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="min-w-0 flex-1"
        />
        <button
          type="submit"
          disabled={enviando || !email.includes("@")}
          className="rounded-input border-[1.5px] border-borda-forte bg-white px-3.5 py-3 text-[13.5px] font-bold text-tinta transition-colors hover:border-primaria hover:text-primaria-hover disabled:opacity-40"
        >
          {enviando ? "Escalando…" : "Escalar"}
        </button>
      </form>

      {erro !== null && (
        <p className="mt-2.5 mb-0 text-[13.5px] text-erro">{erro}</p>
      )}
    </section>
  );
}
