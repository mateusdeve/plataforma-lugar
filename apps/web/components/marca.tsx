import Link from "next/link";

/**
 * A wordmark é "lugar" + um ponto na cor primária. O ponto é a marca —
 * ele repete a ideia do produto: o lugar é seu, ponto final.
 */
export function Marca({
  href = "/",
  className = "",
  tamanho = "text-[26px]",
}: {
  href?: string;
  className?: string;
  tamanho?: string;
}) {
  return (
    <Link
      href={href}
      className={`font-display font-extrabold tracking-[-0.5px] ${tamanho} ${className}`}
    >
      lugar<span className="text-primaria">.</span>
    </Link>
  );
}
