"use client";

import { use, useCallback } from "react";
import { buscarEstadoDaPorta } from "@/lib/dados";
import { useDoOrganizador } from "@/lib/organizador";
import { useSessao } from "@/lib/sessao";
import { AvisoDoOrganizador } from "@/components/aviso-do-organizador";
import { LeitorDeIngresso } from "@/components/leitor-de-ingresso";

/*
  design/portaria/01-leitura.html, 02-resultado-entra.html, 03-resultado-nao-entra.html

  As três telas do handoff são um só componente com três estados — na porta,
  ninguém navega: lê, vê a resposta, lê a próxima.

  ─────────────────────────────────────────────────────────────────────────────
  POR QUE A TELA CARREGA O ESTADO ANTES DE ACEITAR A PRIMEIRA LEITURA

  `GET /api/portaria/{eventoId}` responde 403 se o operador não estiver
  escalado neste evento (PortariaVoter). Chamar isso na abertura faz quem errou
  a porta descobrir AGORA, e não com a primeira pessoa da fila na frente e a
  resposta vermelha na tela.

  E traz o contador com o total real do evento — não o que esta catraca contou.
  Com dois leitores, cada um contando o seu, os dois números estariam errados.
  ─────────────────────────────────────────────────────────────────────────────
*/

export default function PaginaPortaria({
  params,
}: PageProps<"/portaria/[eventoId]">) {
  const { eventoId } = use(params);
  const { ehPortaria, ehOrganizador } = useSessao();

  const estado = useDoOrganizador(
    eventoId,
    useCallback(() => buscarEstadoDaPorta(eventoId), [eventoId]),
    // O organizador dono valida a própria porta sem se escalar — o
    // PortariaVoter concede aos dois caminhos.
    ehPortaria || ehOrganizador,
  );

  if (estado.situacao !== "pronto") {
    return <AvisoDoOrganizador estado={estado} />;
  }

  return (
    <LeitorDeIngresso
      eventoId={estado.dados.eventoId}
      eventoTitulo={estado.dados.eventoTitulo}
      entradasIniciais={estado.dados.entradas}
    />
  );
}
