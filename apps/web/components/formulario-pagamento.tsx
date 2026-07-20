"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ErroDaApi } from "@/lib/api";
import { iniciarCheckout, simularPagamento } from "@/lib/dados";
import { Campo } from "./campo";

/*
  design/comprador/04-checkout.html — bloco de pagamento.

  ─────────────────────────────────────────────────────────────────────────────
  OS CAMPOS DE CARTÃO SÃO DECORATIVOS, E CONTINUARÃO SENDO.

  Nenhum dado de cartão passa por esta aplicação (PRD §6.3). Quando houver
  gateway real, este bloco vira o elemento hospedado dele — um iframe do
  provedor, onde o número é digitado no domínio DELE e nunca toca o nosso
  JavaScript. É o que mantém o projeto fora do escopo de PCI-DSS.

  Por ora os inputs existem para a composição da tela, e o botão faz o caminho
  de verdade: abre a cobrança, o provedor confirma, os ingressos nascem.
  ─────────────────────────────────────────────────────────────────────────────
*/

export function FormularioPagamento({
  reservaId,
  total,
}: {
  reservaId: string;
  total: string;
}) {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [processando, setProcessando] = useState(false);
  const [erro, setErro] = useState<string | null>(null);

  const podePagar = email.includes("@") && !processando;

  async function pagar(evento: React.FormEvent) {
    evento.preventDefault();
    if (!podePagar) return;

    setProcessando(true);
    setErro(null);

    try {
      // 1. abre a cobrança no gateway
      await iniciarCheckout(reservaId);

      // 2. o provedor confirma. Sem gateway real, a API faz esse papel — ver
      //    simularPagamento em lib/dados.ts.
      await simularPagamento(reservaId);

      // 3. os ingressos já existem quando esta linha roda: a emissão acontece
      //    na mesma transação da confirmação.
      router.push(`/reservas/${reservaId}/ingressos`);
    } catch (falha) {
      setProcessando(false);

      // A reserva pode ter vencido enquanto a pessoa preenchia o formulário —
      // é o caso que a tela 06 existe para explicar (PRD §9).
      if (falha instanceof ErroDaApi && falha.chave === "reserva-expirada") {
        router.push(`/reservas/${reservaId}/expirada`);
        return;
      }

      setErro(
        falha instanceof ErroDaApi
          ? falha.message
          : "Não foi possível concluir o pagamento. Tente de novo.",
      );
    }
  }

  return (
    <form
      onSubmit={pagar}
      className="mt-3.5 flex flex-col gap-3.5 rounded-card border border-borda-card bg-white p-5"
    >
      <h2 className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
        Pagamento
      </h2>

      <Campo
        rotulo="Seu e-mail — o ingresso vai pra cá"
        type="email"
        required
        placeholder="ana@email.com"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      <Campo rotulo="Nome no cartão" placeholder="Ana C Souza" />
      <Campo
        rotulo="Número do cartão"
        mono
        inputMode="numeric"
        placeholder="4242 4242 4242 4242"
      />

      <div className="grid grid-cols-2 gap-3">
        <Campo rotulo="Validade" mono inputMode="numeric" placeholder="12/29" />
        <Campo rotulo="CVV" mono inputMode="numeric" placeholder="123" />
      </div>

      {erro !== null && (
        <p role="alert" className="text-[14px] font-semibold text-primaria">
          {erro}
        </p>
      )}

      <button
        type="submit"
        disabled={!podePagar}
        className={`rounded-botao px-4 py-[18px] text-center font-display text-[18px] font-bold transition-colors ${
          processando
            ? "cursor-wait bg-bronze text-off-white"
            : podePagar
              ? "bg-primaria text-off-white hover:bg-primaria-hover"
              : "cursor-not-allowed bg-desabilitado text-off-white"
        }`}
      >
        {processando ? "Confirmando…" : `Pagar ${total}`}
      </button>

      <span className="text-center text-[13px] text-texto-4">
        Ambiente de testes — nenhuma cobrança real.
      </span>
    </form>
  );
}
