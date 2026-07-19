import { Marca } from "@/components/marca";
import { NavOrganizador } from "@/components/nav-organizador";

/*
  Perfil ORGANIZADOR — design/organizador/
  Fundo mais quente que o do comprador e header em tinta: a mudança de
  temperatura sinaliza "você está na área de trabalho", não na loja.

  Na fase 3 do PLAN.md este layout passa a exigir ROLE_ORGANIZADOR no
  middleware. O nome no canto virá de GET /api/me, não de constante.
*/
export default function LayoutOrganizador({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="min-h-screen bg-papel-org text-tinta">
      <header className="bg-tinta text-papel">
        <div className="mx-auto flex max-w-[1120px] items-center gap-3.5 px-6 py-4">
          <Marca href="/" tamanho="text-[22px]" className="text-papel" />
          <span className="rounded-full border border-borda-tinta px-2.5 py-1 text-xs font-bold tracking-[1.5px] text-bronze uppercase">
            Organizador
          </span>
          <div className="ml-auto flex items-center gap-2.5">
            <span className="text-sm text-creme">Rafael M.</span>
            <span className="grid size-[34px] place-items-center rounded-full bg-primaria text-[13px] font-bold text-off-white">
              RM
            </span>
          </div>
        </div>
        <NavOrganizador />
      </header>
      {children}
    </div>
  );
}
