/*
  QR ilustrativo: grade 17×17 derivada por hash do código, igual ao handoff de
  design. Não é um QR legível — é um lugar reservado na composição.

  Na fase 5 do PLAN.md isto vira um QR de verdade (lib `qrcode`) apontando para
  o código único do ingresso. Enquanto não vira, é melhor um padrão obviamente
  decorativo do que um QR quase-válido que alguém tente ler na porta.

  O padrão é determinístico a partir do código — mesma entrada, mesma imagem no
  servidor e no cliente. Nada de Math.random aqui, que causaria divergência de
  hidratação.
*/

const LADO = 17;

function bits(codigo: string): boolean[] {
  let h = 2166136261;
  const saida: boolean[] = [];

  for (let i = 0; i < LADO * LADO; i++) {
    const char = codigo.charCodeAt(i % codigo.length);
    h ^= char + i;
    h = Math.imul(h, 16777619);
    saida.push(((h >>> 7) & 1) === 1);
  }
  return saida;
}

/** Marcadores de canto, como num QR real — é o que faz o padrão ser lido como QR. */
function ehMarcador(linha: number, coluna: number): boolean | null {
  const cantos = [
    [0, 0],
    [0, LADO - 7],
    [LADO - 7, 0],
  ];

  for (const [l0, c0] of cantos) {
    const dl = linha - l0;
    const dc = coluna - c0;
    if (dl < 0 || dl > 6 || dc < 0 || dc > 6) continue;

    const borda = dl === 0 || dl === 6 || dc === 0 || dc === 6;
    const miolo = dl >= 2 && dl <= 4 && dc >= 2 && dc <= 4;
    return borda || miolo;
  }
  return null;
}

export function QrIlustrativo({ codigo }: { codigo: string }) {
  const aleatorios = bits(codigo);

  return (
    <div className="flex-none rounded-xl bg-white p-[9px]">
      <div
        className="grid size-28 grid-cols-17"
        style={{ gridTemplateColumns: `repeat(${LADO}, 1fr)` }}
        role="img"
        aria-label={`Código do ingresso ${codigo}`}
      >
        {Array.from({ length: LADO * LADO }, (_, i) => {
          const linha = Math.floor(i / LADO);
          const coluna = i % LADO;
          const marcador = ehMarcador(linha, coluna);
          const preenchido = marcador ?? aleatorios[i];

          return (
            <span
              key={i}
              className={preenchido ? "bg-tinta-portaria" : "bg-transparent"}
            />
          );
        })}
      </div>
    </div>
  );
}
