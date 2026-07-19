import { HeaderComprador } from "@/components/header-comprador";
import { ProvedorDeSessao } from "@/lib/sessao";

/*
  Perfil COMPRADOR — design/comprador/
  Fundo papel, header leve, conteúdo centrado. A largura máxima muda por tela
  (vitrine 1080, fluxo 640, confirmação 560), então cada página define a sua.

  O provedor de sessão envolve este grupo inteiro: o header precisa saber quem
  está logado, e o checkout precisa do e-mail da conta quando existe.
*/
export default function LayoutComprador({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <ProvedorDeSessao>
      <div className="min-h-screen bg-papel text-tinta">
        <HeaderComprador />
        {children}
      </div>
    </ProvedorDeSessao>
  );
}
