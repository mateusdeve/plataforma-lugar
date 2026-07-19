<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Usuario\Excecao\CredenciaisInvalidas;

/**
 * Raiz de agregado.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ESTA CLASSE NÃO IMPLEMENTA `UserInterface`
 *
 * O reflexo em Symfony é fazer a entidade implementar
 * `Symfony\Component\Security\Core\User\UserInterface`. Aqui isso é proibido —
 * `Domain/` não importa Symfony, e o Deptrac quebra o build se tentar.
 *
 * A ponte é um adaptador em Infrastructure/Seguranca/UsuarioAutenticado, que
 * envolve este objeto e satisfaz o contrato do framework. Custo: uma classe.
 * Retorno: as regras de quem-pode-o-quê são testáveis sem container, sem
 * kernel e sem banco.
 *
 * PAPEL NÃO É PERMISSÃO
 *
 * `ehOrganizador()` diz que a pessoa pode organizar eventos. NÃO diz quais.
 * Quem responde "quais" é o vínculo: `ehDonoDe()` e `estaEscaladoNa()`. Essa
 * distinção é o ADR-004 inteiro, e é a falha de autorização mais comum que
 * existe — o sistema confere o papel, esquece o vínculo, e qualquer
 * organizador lê o faturamento de qualquer outro.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class Usuario
{
    /**
     * @param list<Papel> $papeis
     */
    private function __construct(
        public readonly UsuarioId $id,
        public readonly string $email,
        private string $senhaHash,
        private string $nome,
        private array $papeis,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    /**
     * @param list<Papel> $papeis
     */
    public static function cadastrar(
        UsuarioId $id,
        string $email,
        string $senhaEmTextoPuro,
        string $nome,
        HashDeSenha $hash,
        \DateTimeImmutable $agora,
        array $papeis = [Papel::COMPRADOR],
    ): self {
        $email = mb_strtolower(trim($email));

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail inválido.');
        }

        if ('' === trim($nome)) {
            throw new \InvalidArgumentException('O nome é obrigatório.');
        }

        self::garantirSenhaAceitavel($senhaEmTextoPuro);

        return new self(
            $id,
            $email,
            $hash->gerar($senhaEmTextoPuro),
            trim($nome),
            [] === $papeis ? [Papel::COMPRADOR] : array_values(array_unique($papeis, \SORT_REGULAR)),
            $agora,
        );
    }

    /**
     * @throws CredenciaisInvalidas
     */
    public function autenticarCom(string $senhaEmTextoPuro, HashDeSenha $hash): void
    {
        if (!$hash->confere($senhaEmTextoPuro, $this->senhaHash)) {
            throw new CredenciaisInvalidas();
        }
    }

    public function trocarSenha(string $novaSenha, HashDeSenha $hash): void
    {
        self::garantirSenhaAceitavel($novaSenha);

        $this->senhaHash = $hash->gerar($novaSenha);
    }

    // ── papéis ───────────────────────────────────────────────────────────

    public function tem(Papel $papel): bool
    {
        return \in_array($papel, $this->papeis, true);
    }

    public function ehOrganizador(): bool
    {
        return $this->tem(Papel::ORGANIZADOR);
    }

    public function ehPortaria(): bool
    {
        return $this->tem(Papel::PORTARIA);
    }

    public function conceder(Papel $papel): void
    {
        if (!$this->tem($papel)) {
            $this->papeis[] = $papel;
        }
    }

    public function revogar(Papel $papel): void
    {
        $this->papeis = array_values(array_filter(
            $this->papeis,
            static fn (Papel $p): bool => $p !== $papel,
        ));
    }

    /** @return list<Papel> */
    public function papeis(): array
    {
        return $this->papeis;
    }

    /** @return list<string> */
    public function papeisComoTexto(): array
    {
        return array_map(static fn (Papel $p): string => $p->value, $this->papeis);
    }

    // ── vínculo ──────────────────────────────────────────────────────────

    /**
     * Posse do evento. O `EventoVoter` usa isto, e é o que separa "pode
     * organizar" de "pode organizar ESTE".
     */
    public function ehDonoDe(EventoId $eventoId, ?UsuarioId $organizadorDoEvento): bool
    {
        return null !== $organizadorDoEvento && $this->id->ehIgualA($organizadorDoEvento);
    }

    public function nome(): string
    {
        return $this->nome;
    }

    public function senhaHash(): string
    {
        return $this->senhaHash;
    }

    /**
     * Regra mínima e deliberadamente modesta: 10 caracteres.
     *
     * Sem exigência de símbolo, maiúscula ou dígito. Essas regras produzem
     * `Senha@123` — curta, previsível e presente em toda lista de senhas
     * vazadas. Comprimento é o fator que realmente aumenta o custo de um
     * ataque; complexidade obrigatória só aumenta o de lembrar.
     */
    private static function garantirSenhaAceitavel(string $senha): void
    {
        if (mb_strlen($senha) < 10) {
            throw new \InvalidArgumentException('A senha precisa de ao menos 10 caracteres.');
        }
    }
}
