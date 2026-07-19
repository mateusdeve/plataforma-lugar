import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /*
    O monorepo tem apps/api ao lado. Sem fixar a raiz, o Turbopack sobe a
    árvore procurando lockfile e escolhe o diretório errado — inclusive fora
    do projeto. A Vercel aponta o Root Directory para apps/web, e aqui isso
    fica explícito também para o build local.
  */
  turbopack: {
    root: path.resolve(import.meta.dirname),
  },
};

export default nextConfig;
