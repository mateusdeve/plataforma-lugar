"use client";

import { use, useCallback } from "react";
import { buscarPainel } from "@/lib/dados";
import {
  formatarDataEvento,
  formatarDinheiro,
  formatarRelogio,
} from "@/lib/formato";
import { useDoOrganizador } from "@/lib/organizador";
import { AvisoDoOrganizador } from "@/components/aviso-do-organizador";
import { ExportarCsv } from "@/components/exportar-csv";
import { Pill } from "@/components/pill";
import type { Comprador, EventoStatus, ReservaStatus } from "@/lib/tipos";

/*
  design/organizador/01-painel.html — GET /api/organizador/eventos/{id}/painel

  A rota carrega o id porque o acesso é decidido por evento, e não por papel: o
  EventoVoter compara o dono com quem pediu (ADR-004). Trocar o id na barra de
  endereços para o evento de outro organizador devolve 403 da API, e é a tela
  de "este evento não é seu" que aparece — não uma tela vazia.
*/

const MESES = ["jan","fev","mar","abr","mai","jun","jul","ago","set","out","nov","dez"];

function Estatistica({
  rotulo,
  valor,
  nota,
  corValor = "",
  corNota = "text-texto-4",
  escuro = false,
}: {
  rotulo: string;
  valor: string;
  nota: string;
  corValor?: string;
  corNota?: string;
  escuro?: boolean;
}) {
  return (
    <div
      className={`rounded-card p-5 ${escuro ? "bg-tinta text-papel" : "border border-borda-card bg-white"}`}
    >
      <div
        className={`text-[13px] font-semibold ${escuro ? "text-bronze" : "text-texto-4"}`}
      >
        {rotulo}
      </div>
      <div className={`mt-1 font-display text-[34px] font-extrabold ${corValor}`}>
        {valor}
      </div>
      <div className={`mt-0.5 text-[13px] ${corNota}`}>{nota}</div>
    </div>
  );
}

function pillDoEvento(status: EventoStatus) {
  switch (status) {
    case "PUBLICADO":
      return <Pill tom="sucesso">Publicado</Pill>;
    case "RASCUNHO":
      return <Pill tom="alerta">Rascunho</Pill>;
    case "CANCELADO":
      return <Pill tom="neutro">Cancelado</Pill>;
  }
}

/**
 * O "Pendente · 07:41" é calculado na hora de desenhar, a partir do instante
 * que a API mandou. Um texto pronto vindo do servidor já nasceria atrasado
 * pelo tempo da requisição — e envelheceria em silêncio enquanto a aba fica
 * aberta.
 */
function pillDoComprador(status: ReservaStatus, expiraEm: string | null) {
  switch (status) {
    case "CONFIRMADA":
      return <Pill tom="sucesso">Confirmada</Pill>;
    case "PENDENTE": {
      if (expiraEm === null) return <Pill tom="alerta">Pendente</Pill>;
      const segundos = Math.floor((new Date(expiraEm).getTime() - Date.now()) / 1000);
      return segundos > 0 ? (
        <Pill tom="alerta">Pendente · {formatarRelogio(segundos)}</Pill>
      ) : (
        // A reserva venceu, mas a varredura que a marca como EXPIRADA ainda
        // não passou. Mostrar "Pendente · 00:00" sugeriria que o lugar segue
        // preso — e ele já foi devolvido ao estoque pela query do ADR-002.
        <Pill tom="neutro">Expirando</Pill>
      );
    }
    case "EXPIRADA":
      return <Pill tom="neutro">Expirada</Pill>;
    case "CANCELADA":
      return <Pill tom="neutro">Cancelada</Pill>;
  }
}

function LinhaComprador({
  comprador,
  ultima,
}: {
  comprador: Comprador;
  ultima: boolean;
}) {
  return (
    <div
      className={`flex justify-between gap-2.5 py-[11px] text-[14.5px] ${ultima ? "" : "border-b border-trilha"}`}
    >
      <div className="min-w-0">
        {/* Convidado não tem nome (ADR-004): o e-mail sobe para a linha de
            cima e a de baixo perde a repetição. */}
        <div className="font-semibold">{comprador.nome ?? comprador.email}</div>
        <div className="truncate text-[13px] text-texto-4">
          {comprador.nome !== null && `${comprador.email} · `}
          {comprador.loteNome} · {comprador.quantidade}
        </div>
      </div>
      {pillDoComprador(comprador.status, comprador.expiraEm)}
    </div>
  );
}

export default function PaginaPainel({
  params,
}: PageProps<"/painel/eventos/[id]">) {
  const { id } = use(params);

  const estado = useDoOrganizador(
    id,
    useCallback(() => buscarPainel(id), [id]),
  );

  if (estado.situacao !== "pronto") {
    return <AvisoDoOrganizador estado={estado} />;
  }

  const painel = estado.dados;

  return (
    <main className="mx-auto max-w-[1120px] animate-sobe px-6 pt-[26px] pb-24">
      <div className="mb-5 flex flex-wrap items-baseline gap-3.5">
        <h1 className="m-0 font-display text-[30px] font-extrabold tracking-[-0.7px]">
          {painel.evento.titulo}
        </h1>
        <span className="text-sm text-texto-3">
          {formatarDataEvento(painel.evento.iniciaEm)} · {painel.evento.local},{" "}
          {painel.evento.cidade}
        </span>
        {pillDoEvento(painel.evento.status)}
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
        <Estatistica
          rotulo="Vendidos"
          valor={String(painel.vendidos)}
          nota={
            painel.vendidosHoje > 0 ? `+${painel.vendidosHoje} hoje` : "nenhum hoje"
          }
          corNota={
            painel.vendidosHoje > 0 ? "text-sucesso font-semibold" : "text-texto-4"
          }
        />
        <Estatistica
          rotulo="Reservados agora"
          valor={String(painel.reservadosAgora)}
          nota="seguram lugar até expirar"
          corValor="text-alerta"
        />
        <Estatistica
          rotulo="Disponíveis"
          valor={String(painel.disponiveis)}
          nota="total − vendidos − reservados"
        />
        <Estatistica
          escuro
          rotulo="Receita confirmada"
          valor={formatarDinheiro(painel.receitaConfirmada)}
          nota={`${painel.vendasConfirmadas} ${painel.vendasConfirmadas === 1 ? "venda" : "vendas"}`}
          corNota="text-creme"
        />
      </div>

      {/* Métrica de negócio exigida pelo PRD §6.5 */}
      <section className="mt-3.5 rounded-card border border-borda-card bg-white px-5 py-[18px]">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
            Conversão das reservas
          </h2>
          <span className="text-[13.5px] text-texto-3">
            <strong className="text-sucesso">
              {painel.conversao.viraramVenda}%
            </strong>{" "}
            viram venda ·{" "}
            <strong className="text-alerta">{painel.conversao.expiraram}%</strong>{" "}
            expiram
          </span>
        </div>
        <div className="mt-3 flex h-2.5 overflow-hidden rounded-full">
          <span
            className="bg-sucesso"
            style={{ width: `${painel.conversao.viraramVenda}%` }}
          />
          <span className="flex-1 bg-conversao-trilha" />
        </div>
      </section>

      <div className="mt-3.5 grid grid-cols-[repeat(auto-fit,minmax(340px,1fr))] items-start gap-3.5">
        <section className="rounded-card border border-borda-card bg-white p-5">
          <h2 className="mb-3.5 text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
            Ocupação por lote
          </h2>
          <div className="flex flex-col gap-4">
            {painel.ocupacaoPorLote.map((lote) => {
              const proporcao =
                lote.total > 0 ? (lote.vendidos / lote.total) * 100 : 0;
              const esgotado = lote.situacao === "ESGOTADO";
              const emBreve = lote.situacao === "EM_BREVE";
              const abre = new Date(lote.vendasIniciamEm);

              return (
                <div key={lote.loteId} className="flex flex-col gap-1.5">
                  <div className="flex justify-between text-[14.5px]">
                    <span className="font-bold">
                      {lote.nome} · {formatarDinheiro(lote.preco)}
                    </span>
                    <span className="text-texto-3">
                      {emBreve
                        ? `abre ${abre.getDate()} ${MESES[abre.getMonth()]} · ${lote.total} lugares`
                        : esgotado
                          ? `${lote.vendidos} / ${lote.total} · esgotado`
                          : `${lote.vendidos} / ${lote.total} vendidos`}
                    </span>
                  </div>
                  <div className="h-2 rounded-full bg-trilha">
                    <div
                      className={`h-full rounded-full ${esgotado ? "bg-tinta" : "bg-primaria"}`}
                      style={{ width: `${proporcao}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </section>

        <section className="rounded-card border border-borda-card bg-white p-5">
          <div className="mb-2 flex items-center justify-between">
            <h2 className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
              Compradores
            </h2>
            <ExportarCsv compradores={painel.compradores} />
          </div>

          {painel.compradores.length === 0 ? (
            <p className="py-8 text-center text-[14.5px] text-texto-4">
              Nenhuma reserva ainda.
            </p>
          ) : (
            <div className="flex flex-col">
              {painel.compradores.map((comprador, i) => (
                <LinhaComprador
                  key={comprador.reservaId}
                  comprador={comprador}
                  ultima={i === painel.compradores.length - 1}
                />
              ))}
            </div>
          )}
        </section>
      </div>
    </main>
  );
}
