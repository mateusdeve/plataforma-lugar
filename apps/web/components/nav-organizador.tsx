"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const ABAS = [
  { href: "/painel", rotulo: "Painel" },
  { href: "/painel/eventos/novo", rotulo: "Novo evento" },
] as const;

export function NavOrganizador() {
  const caminho = usePathname();

  return (
    <nav className="mx-auto flex max-w-[1120px] gap-[26px] px-6">
      {ABAS.map((aba) => {
        const ativa = caminho === aba.href;
        return (
          <Link
            key={aba.href}
            href={aba.href}
            aria-current={ativa ? "page" : undefined}
            className={`px-0.5 pt-2.5 pb-3 text-[15px] font-semibold transition-colors ${
              ativa
                ? "text-papel shadow-[inset_0_-3px_0_var(--color-primaria)]"
                : "text-nav-inativa hover:text-papel"
            }`}
          >
            {aba.rotulo}
          </Link>
        );
      })}
    </nav>
  );
}
