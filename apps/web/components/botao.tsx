import Link from "next/link";

const BASE =
  "block w-full rounded-botao px-4 py-[18px] text-center font-display text-[18px] font-bold tracking-[-0.2px] transition-colors";

const ATIVO = "bg-primaria text-off-white hover:bg-primaria-hover";
const INERTE = "cursor-not-allowed bg-desabilitado text-off-white";

/** CTA principal, na forma de link (navegação) ou botão (ação). */
export function BotaoLink({
  href,
  children,
  className = "",
}: {
  href: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <Link href={href} className={`${BASE} ${ATIVO} ${className}`}>
      {children}
    </Link>
  );
}

export function Botao({
  children,
  className = "",
  disabled,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...props}
      disabled={disabled}
      className={`${BASE} ${disabled ? INERTE : ATIVO} ${className}`}
    >
      {children}
    </button>
  );
}
