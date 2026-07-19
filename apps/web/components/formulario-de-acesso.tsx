"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Campo } from "./campo";
import { mensagemDe, useSessao } from "@/lib/sessao";

/*
  Entrar e criar conta no mesmo componente: os dois formulários compartilham
  campos, estados de erro e o destino após o sucesso. Separá-los duplicaria
  tudo isso para trocar um título e um botão.

  O design não cobre estas telas — foram desenhadas a partir dos tokens do
  pacote (papel, tinta, primária, as mesmas medidas de campo do checkout).
*/

export function FormularioDeAcesso({ modo }: { modo: "entrar" | "cadastro" }) {
  const router = useRouter();
  const { entrar, cadastrar } = useSessao();

  const [nome, setNome] = useState("");
  const [email, setEmail] = useState("");
  const [senha, setSenha] = useState("");
  const [organizador, setOrganizador] = useState(false);
  const [erro, setErro] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  const ehCadastro = "cadastro" === modo;

  // A API exige 10 caracteres. Validar aqui também evita uma ida ao servidor
  // para receber de volta algo que a tela já sabia.
  const senhaCurta = ehCadastro && senha.length > 0 && senha.length < 10;
  const podeEnviar =
    email.includes("@") &&
    senha.length >= (ehCadastro ? 10 : 1) &&
    (!ehCadastro || nome.trim().length > 0) &&
    !enviando;

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (!podeEnviar) return;

    setEnviando(true);
    setErro(null);

    try {
      if (ehCadastro) {
        await cadastrar({ nome, email, senha, organizador });
      } else {
        await entrar(email, senha);
      }
      router.push("/");
      router.refresh();
    } catch (e) {
      setErro(mensagemDe(e));
      setEnviando(false);
    }
  }

  return (
    <main className="mx-auto max-w-[440px] animate-sobe px-6 pt-12 pb-28">
      <h1 className="font-display text-[34px] font-extrabold tracking-[-0.8px]">
        {ehCadastro ? "Criar conta" : "Entrar"}
        <span className="text-primaria">.</span>
      </h1>
      <p className="mt-2 mb-7 text-[15.5px] text-texto-2">
        {ehCadastro
          ? "Para acompanhar seus ingressos — ou publicar seus próprios eventos."
          : "Bom te ver de novo."}
      </p>

      <form
        onSubmit={enviar}
        className="flex flex-col gap-3.5 rounded-card border border-borda-card bg-white p-5"
      >
        {ehCadastro && (
          <Campo
            rotulo="Seu nome"
            required
            autoComplete="name"
            placeholder="Ana Souza"
            value={nome}
            onChange={(e) => setNome(e.target.value)}
          />
        )}

        <Campo
          rotulo="E-mail"
          type="email"
          required
          autoComplete="email"
          placeholder="ana@email.com"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />

        <Campo
          rotulo="Senha"
          type="password"
          required
          autoComplete={ehCadastro ? "new-password" : "current-password"}
          placeholder={ehCadastro ? "ao menos 10 caracteres" : "sua senha"}
          value={senha}
          onChange={(e) => setSenha(e.target.value)}
        />

        {senhaCurta && (
          <span className="-mt-1.5 text-[13px] text-texto-4">
            Faltam {10 - senha.length} caracteres. Comprimento protege mais que
            símbolo obrigatório.
          </span>
        )}

        {ehCadastro && (
          <label className="flex cursor-pointer items-start gap-2.5 text-[14px] text-texto-2">
            <input
              type="checkbox"
              checked={organizador}
              onChange={(e) => setOrganizador(e.target.checked)}
              className="mt-0.5 size-4 accent-primaria"
            />
            <span>
              Quero publicar eventos
              <span className="block text-[13px] text-texto-4">
                Habilita o painel do organizador.
              </span>
            </span>
          </label>
        )}

        {erro && (
          <div
            role="alert"
            className="rounded-input border border-conflito-borda bg-conflito-bg px-3.5 py-3 text-[14px] text-conflito-titulo"
          >
            {erro}
          </div>
        )}

        <button
          type="submit"
          disabled={!podeEnviar}
          className={`rounded-botao px-4 py-4 font-display text-[17px] font-bold transition-colors ${
            podeEnviar
              ? "bg-primaria text-off-white hover:bg-primaria-hover"
              : "cursor-not-allowed bg-desabilitado text-off-white"
          }`}
        >
          {enviando ? "Um instante…" : ehCadastro ? "Criar conta" : "Entrar"}
        </button>
      </form>

      <p className="mt-5 text-center text-[14.5px] text-texto-3">
        {ehCadastro ? "Já tem conta? " : "Ainda não tem conta? "}
        <Link
          href={ehCadastro ? "/entrar" : "/cadastro"}
          className="font-semibold text-primaria-hover hover:underline"
        >
          {ehCadastro ? "Entrar" : "Criar agora"}
        </Link>
      </p>

      {!ehCadastro && (
        <div className="mt-8 rounded-card border border-dashed border-borda-card p-4">
          <p className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
            Contas de demonstração
          </p>
          <p className="mt-2 text-[13.5px] leading-relaxed text-texto-3">
            <code className="font-mono">rafael@lugar.demo</code> — organizador
            <br />
            <code className="font-mono">portaria@lugar.demo</code> — portaria
            <br />
            <code className="font-mono">ana@lugar.demo</code> — comprador
            <br />
            <span className="text-texto-4">senha: </span>
            <code className="font-mono">demonstracao123</code>
          </p>
        </div>
      )}
    </main>
  );
}
