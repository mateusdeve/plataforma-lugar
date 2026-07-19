const BASE_INPUT =
  "rounded-input border-[1.5px] border-borda-input bg-papel-input px-3.5 py-3 text-[15px] text-tinta outline-none transition-colors focus:border-primaria";

export function Campo({
  rotulo,
  mono = false,
  className = "",
  ...props
}: {
  rotulo: string;
  mono?: boolean;
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label
      className={`flex flex-col gap-1.5 text-[13.5px] font-semibold text-texto-2 ${className}`}
    >
      {rotulo}
      <input
        {...props}
        className={`${BASE_INPUT} ${mono ? "font-mono" : "font-sans"}`}
      />
    </label>
  );
}

export function CampoTexto({
  rotulo,
  className = "",
  ...props
}: {
  rotulo: string;
} & React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <label
      className={`flex flex-col gap-1.5 text-[13.5px] font-semibold text-texto-2 ${className}`}
    >
      {rotulo}
      <textarea {...props} className={`${BASE_INPUT} resize-y font-sans`} />
    </label>
  );
}
