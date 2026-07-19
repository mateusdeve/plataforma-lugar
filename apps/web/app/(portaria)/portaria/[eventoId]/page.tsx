import { notFound } from "next/navigation";
import { buscarEvento } from "@/lib/dados";
import { LeitorDeIngresso } from "@/components/leitor-de-ingresso";

/*
  design/portaria/01-leitura.html, 02-resultado-entra.html, 03-resultado-nao-entra.html

  As três telas do handoff são um só componente com três estados — na porta,
  ninguém navega: lê, vê a resposta, lê a próxima.

  Na fase 7 do PLAN.md o acesso passa pelo PortariaVoter: o operador só valida
  ingresso de evento em que está escalado (tabela evento_operador). O eventoId
  da URL não autoriza nada sozinho — quem decide é o servidor.
*/

export async function generateMetadata({
  params,
}: PageProps<"/portaria/[eventoId]">) {
  const { eventoId } = await params;
  const evento = await buscarEvento(eventoId);
  return { title: evento ? `Portaria · ${evento.titulo}` : "Portaria — lugar." };
}

export default async function PaginaPortaria({
  params,
}: PageProps<"/portaria/[eventoId]">) {
  const { eventoId } = await params;
  const evento = await buscarEvento(eventoId);
  if (!evento) notFound();

  return <LeitorDeIngresso eventoTitulo={evento.titulo} entradasIniciais={127} />;
}
