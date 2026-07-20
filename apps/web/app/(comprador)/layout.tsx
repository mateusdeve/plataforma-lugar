import { HeaderComprador } from "@/components/header-comprador";

/*
  Perfil COMPRADOR — design/comprador/
  Fundo papel, header leve, conteúdo centrado. A largura máxima muda por tela
  (vitrine 1080, fluxo 640, confirmação 560), então cada página define a sua.

  O provedor de sessão saiu daqui para o layout raiz: a sessão é uma só para o
  app inteiro. Ficando por grupo, ir da vitrine ao painel desmontaria o
  provedor e o access token em memória — a pessoa logada seria deslogada por
  navegar.
*/
export default function LayoutComprador({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="min-h-screen bg-papel text-tinta">
      <HeaderComprador />
      {children}
    </div>
  );
}
