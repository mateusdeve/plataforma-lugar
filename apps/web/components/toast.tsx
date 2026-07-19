"use client";

import { useEffect, useState } from "react";

/** Pill em tinta, topo centro, some sozinho em 3,2s. design/README.md */
export function Toast({
  mensagem,
  aoFechar,
}: {
  mensagem: string | null;
  aoFechar: () => void;
}) {
  useEffect(() => {
    if (!mensagem) return;
    const id = setTimeout(aoFechar, 3200);
    return () => clearTimeout(id);
  }, [mensagem, aoFechar]);

  if (!mensagem) return null;

  return (
    <div
      role="status"
      className="fixed top-5 left-1/2 z-50 -translate-x-1/2 animate-sobe rounded-full bg-tinta px-5 py-2.5 text-[14.5px] font-semibold text-papel shadow-countdown"
    >
      {mensagem}
    </div>
  );
}

export function useToast() {
  const [mensagem, setMensagem] = useState<string | null>(null);
  return {
    mensagem,
    mostrar: setMensagem,
    fechar: () => setMensagem(null),
  };
}
