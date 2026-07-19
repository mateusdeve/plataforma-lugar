"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { api, ErroDaApi } from "./api";

/*
  ─────────────────────────────────────────────────────────────────────────────
  ONDE A SESSÃO VIVE

  O access token fica em MEMÓRIA — neste estado do React, e em nenhum outro
  lugar. Não vai para localStorage nem para cookie legível.

  localStorage seria pior: qualquer script na página o lê, e um XSS levaria a
  sessão inteira. Em memória, o token some quando a aba fecha, e é isso que
  queremos de uma credencial de 15 minutos.

  A sessão longa está no cookie httpOnly de refresh, que o JavaScript não
  alcança. Por isso `restaurar()` roda na montagem: o navegador manda o cookie
  sozinho, a API devolve um access token novo, e a pessoa continua logada
  depois de recarregar a página — sem nada sensível ter passado por JS.

  A renovação ROTACIONA o refresh: cada uso invalida o anterior (ADR-004).
  ─────────────────────────────────────────────────────────────────────────────
*/

export type Usuario = {
  id: string;
  nome: string;
  email: string;
  papeis: string[];
};

type Sessao = {
  usuario: Usuario | null;
  carregando: boolean;
  entrar: (email: string, senha: string) => Promise<void>;
  cadastrar: (dados: {
    nome: string;
    email: string;
    senha: string;
    organizador: boolean;
  }) => Promise<void>;
  sair: () => Promise<void>;
  ehOrganizador: boolean;
  ehPortaria: boolean;
};

type Resposta = { accessToken: string; usuario: Usuario };

const Contexto = createContext<Sessao | null>(null);

/** Guardado fora do estado do React para o cliente HTTP poder lê-lo. */
let accessToken: string | null = null;

export function tokenAtual(): string | null {
  return accessToken;
}

export function ProvedorDeSessao({ children }: { children: React.ReactNode }) {
  const [usuario, setUsuario] = useState<Usuario | null>(null);
  const [carregando, setCarregando] = useState(true);

  const aplicar = useCallback((r: Resposta) => {
    accessToken = r.accessToken;
    setUsuario(r.usuario);
  }, []);

  useEffect(() => {
    // Tenta restaurar a sessão pelo cookie de refresh. Falhar aqui é o caso
    // normal de quem nunca entrou — não é erro, é ausência de sessão.
    api
      .post<Resposta>("/api/auth/refresh")
      .then(aplicar)
      .catch(() => setUsuario(null))
      .finally(() => setCarregando(false));
  }, [aplicar]);

  const entrar = useCallback(
    async (email: string, senha: string) => {
      aplicar(await api.post<Resposta>("/api/auth/login", { email, senha }));
    },
    [aplicar],
  );

  const cadastrar = useCallback(
    async (dados: {
      nome: string;
      email: string;
      senha: string;
      organizador: boolean;
    }) => {
      aplicar(
        await api.post<Resposta>("/api/auth/cadastro", {
          nome: dados.nome,
          email: dados.email,
          senha: dados.senha,
          // O único papel que alguém pode conceder a si mesmo. Portaria vem
          // da escala feita pelo organizador dono do evento (ADR-004).
          papel: dados.organizador ? "organizador" : "comprador",
        }),
      );
    },
    [aplicar],
  );

  const sair = useCallback(async () => {
    await api.post("/api/auth/logout").catch(() => undefined);
    accessToken = null;
    setUsuario(null);
  }, []);

  return (
    <Contexto.Provider
      value={{
        usuario,
        carregando,
        entrar,
        cadastrar,
        sair,
        ehOrganizador: usuario?.papeis.includes("ROLE_ORGANIZADOR") ?? false,
        ehPortaria: usuario?.papeis.includes("ROLE_PORTARIA") ?? false,
      }}
    >
      {children}
    </Contexto.Provider>
  );
}

export function useSessao(): Sessao {
  const ctx = useContext(Contexto);

  if (null === ctx) {
    throw new Error("useSessao precisa estar dentro de <ProvedorDeSessao>.");
  }

  return ctx;
}

/** Traduz o erro da API para uma frase que a pessoa entende. */
export function mensagemDe(erro: unknown): string {
  if (erro instanceof ErroDaApi) {
    switch (erro.chave) {
      case "credenciais-invalidas":
        return "E-mail ou senha incorretos.";
      case "email-ja-cadastrado":
        return "Este e-mail já tem conta. Tente entrar.";
      case "muitas-tentativas":
        return "Muitas tentativas seguidas. Aguarde um minuto.";
      default:
        return erro.message;
    }
  }

  return "Não foi possível concluir. Tente de novo.";
}
