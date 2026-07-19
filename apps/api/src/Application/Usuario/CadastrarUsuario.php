<?php

declare(strict_types=1);

namespace Lugar\Application\Usuario;

use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Usuario\Excecao\EmailJaCadastrado;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;

final readonly class CadastrarUsuario
{
    public function __construct(
        private RepositorioDeUsuarios $usuarios,
        private HashDeSenha $hash,
        private GeradorDeIdentidade $gerador,
        private Relogio $relogio,
    ) {
    }

    /**
     * @param list<Papel> $papeis
     */
    public function __invoke(
        string $email,
        string $senha,
        string $nome,
        array $papeis = [Papel::COMPRADOR],
    ): Usuario {
        if (null !== $this->usuarios->buscarPorEmail($email)) {
            throw new EmailJaCadastrado('Este e-mail já está cadastrado.');
        }

        $usuario = Usuario::cadastrar(
            $this->gerador->novoUsuarioId(),
            $email,
            $senha,
            $nome,
            $this->hash,
            $this->relogio->agora(),
            $papeis,
        );

        // A verificação acima é uma corrida: dois cadastros simultâneos com o
        // mesmo e-mail passam os dois. O UNIQUE em usuario.email é quem
        // garante de verdade — aqui só produzimos a mensagem amigável no caso
        // comum.
        $this->usuarios->salvar($usuario);

        return $usuario;
    }
}
