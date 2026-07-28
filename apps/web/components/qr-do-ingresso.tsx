"use client";

import { useEffect, useState } from "react";
import QRCode from "qrcode";

/*
  ─────────────────────────────────────────────────────────────────────────────
  QR DE VERDADE (PLAN 5.7).

  Substitui o `qr-ilustrativo`, que era uma grade decorativa derivada por hash —
  bonita e ilegível. Na porta, um QR que não lê é pior que QR nenhum: a pessoa
  tenta, falha, a fila para, e só então alguém digita o código na mão.

  O CONTEÚDO É O CÓDIGO, E NADA MAIS

  Nada de URL. Um QR com `https://.../ingressos/LGR-XXXX-XXXX` convida o
  celular a abrir o navegador na porta do evento, onde a internet é ruim — e
  faz a validação depender de a página carregar. O leitor da portaria espera o
  código; digitar na mão continua funcionando pelo mesmo caminho.

  Correção de erro em nível médio: 15% do símbolo pode estar danificado e ainda
  assim lê. Ingresso vive amassado no bolso, ou numa tela rachada.
  ─────────────────────────────────────────────────────────────────────────────
*/

export function QrDoIngresso({ codigo }: { codigo: string }) {
  const [imagem, setImagem] = useState<string | null>(null);

  useEffect(() => {
    let atual = true;

    QRCode.toDataURL(codigo, {
      errorCorrectionLevel: "M",
      margin: 0,
      width: 220,
      // Alto contraste e cores fixas: o cartão do ingresso é escuro, mas o QR
      // precisa do fundo claro para o leitor separar os módulos.
      color: { dark: "#141210", light: "#ffffff" },
    })
      .then((url) => {
        if (atual) setImagem(url);
      })
      .catch(() => {
        // Falhar aqui não pode esconder o código: ele aparece em texto ao lado,
        // e é ele que vale na entrada.
        if (atual) setImagem(null);
      });

    return () => {
      atual = false;
    };
  }, [codigo]);

  return (
    <div className="flex size-[122px] flex-none items-center justify-center rounded-xl bg-white p-[9px]">
      {imagem === null ? (
        <span className="size-full animate-pulse rounded bg-neutro-bg" />
      ) : (
        /*
          `next/image` otimiza arquivos servidos por URL. Aqui a fonte é um
          `data:` URI gerado no próprio navegador — não há o que otimizar, nem
          rede a poupar. Passar por ele só acrescentaria peso.
        */
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={imagem}
          alt={`QR do ingresso ${codigo}`}
          className="size-full"
        />
      )}
    </div>
  );
}
