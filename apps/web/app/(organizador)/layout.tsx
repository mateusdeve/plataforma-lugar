import { IdentidadeOrganizador } from "@/components/identidade-organizador";
import { Marca } from "@/components/marca";
import { NavOrganizador } from "@/components/nav-organizador";

/*
  Perfil ORGANIZADOR — design/organizador/
  Fundo mais quente que o do comprador e header em tinta: a mudança de
  temperatura sinaliza "você está na área de trabalho", não na loja.

  Este layout NÃO decide acesso, e não é esquecimento. Quem decide é o Symfony,
  em toda requisição (PLAN.md §4): o que não vier autorizado não chega à tela,
  independentemente do que o front mostre. As páginas tratam 401 e 403 da API
  como estados de verdade — ver lib/organizador.ts. Esconder o menu seria
  conveniência de UX, e conveniência de UX que passa por segurança vira a
  desculpa para não checar no servidor.
*/

export const metadata = { title: "Painel — lugar." };

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
          <IdentidadeOrganizador />
        </div>
        <NavOrganizador />
      </header>
      {children}
    </div>
  );
}
