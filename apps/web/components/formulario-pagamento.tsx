"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Campo } from "./campo";

/*
  design/comprador/04-checkout.html — bloco de pagamento.

  Nenhum dado de cartão passará pela aplicação de verdade (PRD §6.3): na fase 5
  estes campos são substituídos pelo elemento hospedado do gateway, e o que
  chega aqui é um token. Os inputs abaixo existem para a composição da tela.
*/

export function FormularioPagamento({
  total,
  codigoIngresso,
}: {
  total: string;
  codigoIngresso: string;
}) {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [processando, setProcessando] = useState(false);

  const podePagar = email.includes("@") && !processando;

  function pagar(evento: React.FormEvent) {
    evento.preventDefault();
    if (!podePagar) return;

    setProcessando(true);
    // POST /api/reservas/{id}/checkout → o gateway responde e o webhook confirma.
    setTimeout(() => router.push(`/ingressos/${codigoIngresso}`), 1400);
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
