const TONS = {
  sucesso: "bg-sucesso-bg text-sucesso",
  alerta: "bg-alerta-bg text-alerta",
  neutro: "bg-neutro-bg text-texto-4",
} as const;

export type TomPill = keyof typeof TONS;

export function Pill({
  tom,
  children,
}: {
  tom: TomPill;
  children: React.ReactNode;
}) {
  return (
    <span
      className={`h-fit rounded-full px-2.5 py-[5px] text-xs font-bold whitespace-nowrap ${TONS[tom]}`}
    >
      {children}
    </span>
  );
}
