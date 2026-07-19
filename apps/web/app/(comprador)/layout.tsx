import Link from "next/link";
import { Marca } from "@/components/marca";

/*
  Perfil COMPRADOR — design/comprador/
  Fundo papel, header leve, conteúdo centrado. A largura máxima muda por tela
  (vitrine 1080, fluxo 640, confirmação 560), então cada página define a sua.
*/
export default function LayoutComprador({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="min-h-screen bg-papel text-tinta">
      <header className="mx-auto flex max-w-[1080px] items-center justify-between px-6 pt-[22px] pb-1.5">
        <Marca href="/" />
        <Link
          href="/painel"
          className="text-sm font-semibold text-texto-3 hover:text-primaria-hover"
        >
          Sou organizador
        </Link>
      </header>
      {children}
    </div>
  );
}
