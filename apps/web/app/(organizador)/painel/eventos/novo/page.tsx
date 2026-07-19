import { FormularioNovoEvento } from "@/components/formulario-novo-evento";

/* design/organizador/02-novo-evento.html — POST /api/eventos + lotes */

export const metadata = { title: "Novo evento — lugar." };

export default function PaginaNovoEvento() {
  return (
    <main className="mx-auto max-w-[720px] animate-sobe px-6 pt-[26px] pb-24">
      <h1 className="mb-1.5 font-display text-[30px] font-extrabold tracking-[-0.7px]">
        Novo evento
      </h1>
      <p className="mb-[22px] text-[15px] text-texto-3">
        Preencha o básico, defina os lotes e publique quando quiser.
      </p>
      <FormularioNovoEvento />
    </main>
  );
}
