import type { Metadata } from "next";
import {
  Bricolage_Grotesque,
  Instrument_Sans,
  Spline_Sans_Mono,
} from "next/font/google";
import "./globals.css";

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
      <body className="min-h-full font-sans antialiased">{children}</body>
    </html>
  );
}
