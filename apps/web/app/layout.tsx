import type { Metadata } from "next";
import {
  Bricolage_Grotesque,
  Instrument_Sans,
  Spline_Sans_Mono,
} from "next/font/google";
import "./globals.css";
import { ProvedorDeSessao } from "@/lib/sessao";

const display = Bricolage_Grotesque({
  variable: "--fonte-display",
  subsets: ["latin"],
  weight: ["400", "700", "800"],
});

const corpo = Instrument_Sans({
  variable: "--fonte-corpo",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
});

const mono = Spline_Sans_Mono({
  variable: "--fonte-mono",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
});

export const metadata: Metadata = {
  title: "lugar.",
  description:
    "Reserve seu ingresso e pague com calma — seu lugar fica guardado enquanto isso.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="pt-BR"
      className={`${display.variable} ${corpo.variable} ${mono.variable} h-full`}
    >
      {/*
        A sessão envolve o app inteiro, e não cada grupo de rotas: o access
        token vive em memória (lib/api.ts) e uma navegação entre grupos
        desmontaria o provedor junto com ele. Aqui, uma restauração pelo cookie
        de refresh serve comprador, organizador e portaria.
      */}
      <body className="min-h-full font-sans antialiased">
        <ProvedorDeSessao>{children}</ProvedorDeSessao>
      </body>
    </html>
  );
}
