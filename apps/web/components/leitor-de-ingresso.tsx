"use client";

import { useEffect, useRef, useState } from "react";
import { validarIngresso } from "@/lib/dados";
import { formatarHora } from "@/lib/formato";
import type { MotivoRecusa, ResultadoValidacao } from "@/lib/tipos";

/*
  Tela única de leitura. Requisitos que vêm do uso real, não da estética
  (PRD §10.3 e design/README.md):

    · resposta binária e grande — quem confere está em pé, com fila na frente
    · recusa SEMPRE com motivo — "não entra" sem explicação gera discussão
    · Enter valida, foco volta sozinho — a leitura seguinte não espera clique
    · overlay some em 2,8s ou ao toque, o que vier antes
*/

const MILISSEGUNDOS_ATE_SUMIR = 2800;

const MENSAGEM_DE_RECUSA: Record<MotivoRecusa, string> = {
  JA_UTILIZADO: "Já utilizado",
  CODIGO_INVALIDO: "Código inválido para este evento",
  EVENTO_ERRADO: "Ingresso é de outro evento",
  CANCELADO: "Ingresso cancelado",
};

function textoDaRecusa(
  motivo: MotivoRecusa,
  utilizadoEm: string | null,
): string {
  if (motivo === "JA_UTILIZADO" && utilizadoEm) {
    return `Já utilizado às ${formatarHora(utilizadoEm)}`;
  }
  return MENSAGEM_DE_RECUSA[motivo];
}

export function LeitorDeIngresso({
  eventoId,
  eventoTitulo,
  entradasIniciais,
}: {
  eventoId: string;
  eventoTitulo: string;
  entradasIniciais: number;
}) {
  const [codigo, setCodigo] = useState("");
  const [resultado, setResultado] = useState<ResultadoValidacao | null>(null);
  const [entradas, setEntradas] = useState(entradasIniciais);
  const [validando, setValidando] = useState(false);

  const campo = useRef<HTMLInputElement>(null);

  async function validar(evento: React.FormEvent) {
    evento.preventDefault();
    if (!codigo.trim() || validando) return;

    setValidando(true);

    /*
      Nenhuma decisão acontece aqui.

      A versão anterior guardava os códigos já lidos numa ref e recusava a
      segunda passada localmente. Funcionava com UMA catraca — e era o bug que
      a RN-10 existe para impedir: com dois leitores, cada um tem a sua
      memória, e o mesmo ingresso entra duas vezes.

      Quem decide é o servidor, sob lock. Ver ValidarIngresso na API.
    */
    const veredito = await validarIngresso(codigo, eventoId);

    if (veredito.entra) setEntradas((n) => n + 1);

    setResultado(veredito);
    setCodigo("");
    setValidando(false);
  }

  function dispensar() {
    setResultado(null);
    campo.current?.focus();
  }

  useEffect(() => {
    if (!resultado) return;
    const id = setTimeout(dispensar, MILISSEGUNDOS_ATE_SUMIR);
    return () => clearTimeout(id);
  }, [resultado]);

  return (
    <div className="flex min-h-screen flex-col bg-tinta-portaria text-papel">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-borda-portaria px-6 py-5">
        <div className="flex flex-wrap items-baseline gap-3">
          <span className="font-display text-xl font-extrabold">
            lugar<span className="text-primaria">.</span>
          </span>
          <span className="text-sm font-semibold text-creme">
            Portaria · {eventoTitulo}
          </span>
        </div>
        <span className="font-mono text-sm text-bronze" aria-live="polite">
          {entradas} entradas hoje
        </span>
      </header>

      <form
        onSubmit={validar}
        className="flex flex-1 flex-col items-center justify-center gap-[18px] px-6 pt-8 pb-20"
      >
        <label
          htmlFor="codigo"
          className="text-[13px] font-bold tracking-[2px] text-texto-4 uppercase"
        >
          Código do ingresso
        </label>

        <input
          id="codigo"
          ref={campo}
          value={codigo}
          onChange={(e) => setCodigo(e.target.value.toUpperCase())}
          autoFocus
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          placeholder="LGR-0000-0000"
          className="w-[min(420px,100%)] rounded-[16px] border-2 border-trilha-escura bg-tinta px-4 py-5 text-center font-mono text-[26px] font-bold tracking-[2px] text-papel uppercase outline-none focus:border-primaria"
        />

        <button
          type="submit"
          disabled={!codigo.trim() || validando}
          className="w-[min(420px,100%)] rounded-[16px] bg-primaria px-4 py-5 font-display text-xl font-extrabold text-off-white transition-colors hover:bg-primaria-hover disabled:opacity-50"
        >
          {validando ? "Validando…" : "Validar entrada"}
        </button>

        {/*
          Os botões de código de exemplo saíram: eles apontavam para códigos
          fixos do mock. Agora os códigos são gerados na emissão, aleatórios
          por RN-09 — um atalho fixo aqui apontaria para nada.
        */}
      </form>

      {resultado && (
        <button
          type="button"
          onClick={dispensar}
          aria-live="assertive"
          className={`fixed inset-0 z-50 flex animate-surge flex-col items-center justify-center p-8 text-center ${
            resultado.entra ? "bg-sucesso" : "bg-erro"
          }`}
        >
          <span className="font-display text-[clamp(56px,12vw,120px)] leading-none font-extrabold tracking-[-2px] text-off-white-alto">
            {resultado.entra ? "PODE ENTRAR" : "NÃO ENTRA"}
          </span>

          <span className="mt-[18px] text-[clamp(18px,3vw,26px)] font-bold text-off-white-alto/90">
            {resultado.entra
              ? [
                  resultado.compradorNome,
                  resultado.loteNome,
                  `${resultado.quantidade} ${resultado.quantidade === 1 ? "ingresso" : "ingressos"}`,
                ]
                  .filter(Boolean)
                  .join(" · ")
              : textoDaRecusa(resultado.motivo, resultado.utilizadoEm)}
          </span>

          <span className="mt-2.5 font-mono text-[15px] text-off-white-alto/70">
            {resultado.codigo}
          </span>

          <span className="mt-10 text-[13.5px] text-off-white-alto/60">
            toque para continuar
          </span>
        </button>
      )}
    </div>
  );
}
