<?php

declare(strict_types=1);

namespace Lugar\Application\Usuario;

use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Usuario\Excecao\CredenciaisInvalidas;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\RepositorioDeTokens;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\TokenDeRenovacao;

/**
 * Login e renovação de sessão.
 *
 * `EmissorDeAccessToken` é uma porta: o caso de uso emite um token sem saber
 * que ele é um JWT assinado com RS256 por um bundle. Trocar o mecanismo depois
 * não toca aqui.
 */
final readonly class AbrirSessao
{
    public function __construct(
        private RepositorioDeUsuarios $usuarios,
        private RepositorioDeTokens $tokens,
        private HashDeSenha $hash,
        private EmissorDeAccessToken $emissor,
        private Relogio $relogio,
        private int $validadeDoRefreshEmDias,
    ) {
    }

    /**
     * @throws CredenciaisInvalidas
     */
    public function comCredenciais(string $email, string $senha): Sessao
    {
        $usuario = $this->usuarios->buscarPorEmail($email);

        if (null === $usuario) {
            // MESMA exceção de senha errada, de propósito. Distinguir os dois
            // casos revela quais e-mails estão cadastrados — é assim que se
            // enumera a base de usuários de um sistema.
            throw new CredenciaisInvalidas();
        }

        $usuario->autenticarCom($senha, $this->hash);

        return $this->abrirPara($usuario);
    }

    /**
     * Renovação COM ROTAÇÃO: o token apresentado é revogado e outro é emitido.
     *
     * É isso que torna roubo de refresh token detectável. Se o token furtado
     * for usado, o legítimo para de funcionar e a pessoa é deslogada — um
     * sinal visível. Sem rotação, o ladrão renova em silêncio por 30 dias.
     *
     * @throws CredenciaisInvalidas
     */
    public function renovando(string $refreshEmTextoPuro): Sessao
    {
        $agora = $this->relogio->agora();

        $token = $this->tokens->buscarPorHash(TokenDeRenovacao::hashDe($refreshEmTextoPuro));

        if (null === $token || !$token->estaValido($agora)) {
            throw new CredenciaisInvalidas();
        }

        $usuario = $this->usuarios->buscar($token->usuarioId);

        if (null === $usuario) {
            throw new CredenciaisInvalidas();
        }

        $token->revogar($agora);
        $this->tokens->salvar($token);

        return $this->abrirPara($usuario);
    }

    public function encerrar(string $refreshEmTextoPuro): void
    {
        $token = $this->tokens->buscarPorHash(TokenDeRenovacao::hashDe($refreshEmTextoPuro));

        $token?->revogar($this->relogio->agora());

        if (null !== $token) {
            $this->tokens->salvar($token);
        }
    }

    private function abrirPara(\Lugar\Domain\Usuario\Usuario $usuario): Sessao
    {
        $agora = $this->relogio->agora();

        // 32 bytes de aleatoriedade criptográfica. `random_bytes` e não
        // `uniqid` ou `rand`: este valor É a sessão de 30 dias da pessoa.
        $refreshEmTextoPuro = bin2hex(random_bytes(32));

        $token = TokenDeRenovacao::emitir(
            $refreshEmTextoPuro,
            $usuario->id,
            $agora,
            $this->validadeDoRefreshEmDias,
        );

        $this->tokens->salvar($token);

        return new Sessao(
            $usuario,
            $this->emissor->para($usuario),
            $refreshEmTextoPuro,
            $token->expiraEm,
        );
    }
}
