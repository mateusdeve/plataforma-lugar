import Link from "next/link";

/*
  Índice navegável de todas as telas — equivalente ao design/index.html.
  Serve para revisar o front sem precisar percorrer o fluxo inteiro, e some
  quando o produto tiver navegação real de verdade.
*/

export const metadata = { title: "Telas — lugar." };

const PERFIS = [
  {
    perfil: "Comprador",
    pasta: "design/comprador/",
    cor: "bg-papel",
    telas: [
      { href: "/", nome: "Vitrine", origem: "01-vitrine.html", api: "GET /api/eventos" },
      {
        href: "/eventos/frontz-conf-2026",
        nome: "Detalhe do evento",
        origem: "02-evento.html",
        api: "GET /api/eventos/{id}",
      },
      {
        href: "/eventos/frontz-conf-2026?erro=estoque-insuficiente&lote=frontz-lote-2",
        nome: "Esgotou enquanto decidia (409)",
        origem: "03-evento-esgotou-409.html",
        api: "type=estoque-insuficiente",
      },
      {
        href: "/checkout/res-demo",
        nome: "Checkout com contador",
        origem: "04-checkout.html",
        api: "GET /api/reservas/{id}",
      },
      {
        href: "/ingressos/LGR-8F3K-92QX",
        nome: "Ingresso emitido",
        origem: "05-confirmada.html",
        api: "GET /api/ingressos/{codigo}",
      },
      {
        href: "/reservas/res-demo/expirada",
        nome: "Reserva expirada",
        origem: "06-expirada.html",
        api: "type=reserva-expirada",
      },
    ],
  },
  {
    perfil: "Organizador",
    pasta: "design/organizador/",
    cor: "bg-papel-org",
    telas: [
      {
        href: "/painel",
        nome: "Painel",
        origem: "01-painel.html",
        api: "GET /api/organizador/eventos/{id}/painel",
      },
      {
        href: "/painel/eventos/novo",
        nome: "Novo evento",
        origem: "02-novo-evento.html",
        api: "POST /api/eventos",
      },
    ],
  },
  {
    perfil: "Portaria",
    pasta: "design/portaria/",
    cor: "bg-tinta-portaria",
    telas: [
      {
        href: "/portaria/frontz-conf-2026",
        nome: "Leitura e resultado",
        origem: "01, 02 e 03 — um componente, três estados",
        api: "POST /api/ingressos/{codigo}/utilizar",
      },
    ],
  },
] as const;

export default function Guia() {
  return (
    <div className="min-h-screen bg-papel text-tinta">
      <main className="mx-auto max-w-[860px] px-6 py-14">
        <h1 className="font-display text-[40px] font-extrabold tracking-[-1px]">
          Telas<span className="text-primaria">.</span>
        </h1>
        <p className="mt-3 mb-10 max-w-[60ch] text-[16px] leading-[1.6] text-texto-2">
          Todas as telas do MVP recriadas em Next.js e Tailwind a partir do
          pacote em <code className="font-mono text-[14px]">design/</code>. Os
          dados são de exemplo e vêm de{" "}
          <code className="font-mono text-[14px]">lib/dados.ts</code> — o único
          arquivo que muda quando a API Symfony existir.
        </p>

        <div className="flex flex-col gap-8">
          {PERFIS.map((grupo) => (
            <section key={grupo.perfil}>
              <div className="mb-3 flex items-baseline gap-3">
                <span
                  className={`size-3 rounded-full border border-borda-card ${grupo.cor}`}
                  aria-hidden
                />
                <h2 className="text-[13px] font-bold tracking-[1.5px] text-texto-4 uppercase">
                  {grupo.perfil}
                </h2>
                <span className="font-mono text-[12.5px] text-texto-tenue">
                  {grupo.pasta}
                </span>
              </div>

              <div className="flex flex-col gap-2">
                {grupo.telas.map((tela) => (
                  <Link
                    key={tela.href}
                    href={tela.href}
                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-card border border-borda-card bg-white px-5 py-4 transition-all hover:-translate-y-0.5 hover:border-primaria hover:shadow-card-hover"
                  >
                    <span className="text-[15.5px] font-bold">{tela.nome}</span>
                    <span className="font-mono text-[12.5px] text-texto-4">
                      {tela.api}
                    </span>
                    <span className="w-full font-mono text-[12px] text-texto-tenue">
                      {tela.origem}
                    </span>
                  </Link>
                ))}
              </div>
            </section>
          ))}
        </div>
      </main>
    </div>
  );
}
