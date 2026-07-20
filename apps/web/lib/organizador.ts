"use client";

import { useEffect, useState } from "react";
import { ErroDaApi } from "./api";
import { useSessao } from "./sessao";

/*
  ─────────────────────────────────────────────────────────────────────────────
  OS ESTADOS DE UMA TELA DO ORGANIZADOR

  São seis, e a tentação é achar que são dois ("carregando" e "pronto"). As
  telas do painel carregam dados de UMA CONTA, e por isso podem falhar de
  maneiras que a vitrine não pode:

    carregando  a sessão ainda está sendo restaurada pelo cookie de refresh —
                não dá para pedir nada antes disso, porque a requisição sairia
                sem token e levaria 401 de quem ESTÁ logado
    sem-sessao  ninguém entrou
    sem-papel   entrou, mas não é organizador
    negado      403 do EventoVoter: o evento existe e não é seu (ADR-004)
    ausente     404: o evento não existe
    erro        o resto

  Achatar `negado` e `ausente` num só é o atalho que apaga da tela justamente a
  regra que este projeto existe para demonstrar. "Não encontrado" para um
  evento que existe é uma mentira educada; "este evento não é seu" é a verdade,
  e é ela que o organizador precisa ler para entender o sistema.

  Por que o dado é buscado no NAVEGADOR, e não em Server Component: o access
  token vive em memória, na aba (ver lib/api.ts). O servidor do Next não o tem
  e não deve tê-lo — encaminhar credencial de sessão por ele criaria um segundo
  lugar onde a sessão existe, e um segundo lugar de onde ela pode vazar.
  ─────────────────────────────────────────────────────────────────────────────
*/

export type Estado<T> =
  | { situacao: "carregando" }
  | { situacao: "sem-sessao" }
  | { situacao: "sem-papel" }
  | { situacao: "negado" }
  | { situacao: "ausente" }
  | { situacao: "erro"; mensagem: string }
  | { situacao: "pronto"; dados: T };

/**
 * Tudo menos "pronto" — o que a tela de aviso sabe desenhar.
 *
 * Existe para o compilador cobrar o caso novo: acrescentar uma situação aqui
 * quebra `AvisoDoOrganizador` até que ela ganhe texto. Um `default: "algo deu
 * errado"` aceitaria o caso novo em silêncio, que é como situações distintas
 * viram todas a mesma tela genérica.
 */
export type EstadoSemDados = Exclude<Estado<unknown>, { situacao: "pronto" }>;

/**
 * `chave` identifica O QUE está sendo carregado — o id do evento, por exemplo.
 *
 * `carregar` PRECISA vir de `useCallback`: entra na lista de dependências do
 * efeito, e uma função recriada a cada render dispararia a busca de novo, sem
 * parar. Guardá-la numa ref evitaria isso, mas escrever numa ref durante a
 * renderização é justamente o que o React Compiler proíbe — e com razão, é uma
 * escrita invisível para ele.
 */
export function useDoOrganizador<T>(
  chave: string,
  carregar: () => Promise<T>,
  /**
   * O papel que a TELA exige para tentar carregar. É conveniência de UX, não
   * segurança: quem decide de verdade é o Symfony, em toda requisição
   * (PLAN.md §4). Serve para não disparar um pedido que já se sabe que levará
   * 403, e para dar uma mensagem melhor que "erro".
   *
   * A portaria passa `ehPortaria || ehOrganizador` — o organizador dono valida
   * a própria porta sem se escalar (PortariaVoter).
   */
  temPapel?: boolean,
): Estado<T> {
  const { usuario, carregando, ehOrganizador } = useSessao();
  const autorizadoNaTela = temPapel ?? ehOrganizador;

  /*
    A resposta guarda a chave que a produziu.

    Sem isso, seria preciso zerar o estado no começo do efeito — um setState
    síncrono que provoca renderização em cascata. Com a chave junto, "ainda não
    carreguei ISTO" é uma comparação feita na renderização, e o efeito só
    escreve estado quando a requisição volta.
  */
  const [resposta, setResposta] = useState<
    { chave: string; estado: Estado<T> } | null
  >(null);

  useEffect(() => {
    if (carregando || usuario === null || !autorizadoNaTela) return;

    // A resposta de um pedido antigo não pode sobrescrever a de um novo: se a
    // pessoa troca de evento antes da primeira chegar, a tela mostraria os
    // números do evento errado — com o título do certo.
    let atual = true;

    carregar()
      .then((dados) => {
        if (atual) setResposta({ chave, estado: { situacao: "pronto", dados } });
      })
      .catch((erro: unknown) => {
        if (atual) setResposta({ chave, estado: traduzir(erro) });
      });

    return () => {
      atual = false;
    };
  }, [chave, carregar, carregando, usuario, autorizadoNaTela]);

  // Os estados de sessão são DERIVADOS, não guardados: são função direta do que
  // o provedor já sabe. Copiá-los para dentro de um useState criaria uma
  // segunda versão da mesma verdade, atrasada em um render.
  if (carregando) return { situacao: "carregando" };
  if (usuario === null) return { situacao: "sem-sessao" };
  if (!autorizadoNaTela) return { situacao: "sem-papel" };

  return resposta !== null && resposta.chave === chave
    ? resposta.estado
    : { situacao: "carregando" };
}

function traduzir<T>(erro: unknown): Estado<T> {
  if (erro instanceof ErroDaApi) {
    if (erro.status === 403) return { situacao: "negado" };
    if (erro.status === 404) return { situacao: "ausente" };
    // 401 com sessão restaurada significa access token vencido (15 min). O
    // caminho honesto é mandar entrar de novo, não mostrar "erro genérico".
    if (erro.status === 401) return { situacao: "sem-sessao" };
  }

  return {
    situacao: "erro",
    mensagem: "Não foi possível carregar. Tente de novo em instantes.",
  };
}
