<?php

declare(strict_types=1);

namespace Lugar\Tests\Dominio\Usuario;

use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\Usuario;
use Lugar\Domain\Usuario\UsuarioId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PAPEL NÃO BASTA. É PRECISO VÍNCULO.
 *
 * O ADR-004 chama isto de segundo melhor argumento técnico do projeto, atrás
 * só do teste de concorrência. O caso que importa não é "usuário sem papel
 * recebe 403" — esse quase nunca falha. É este:
 *
 *   um organizador AUTENTICADO, com o papel CORRETO, tentando ler o painel de
 *   um evento ALHEIO.
 *
 * Um sistema que confere só o papel deixa isso passar. E passa despercebido,
 * porque a tela nunca oferece o link — mas a API não sabe o que a tela
 * oferece, e quem chama a API direto não passa pela tela.
 *
 * Repare que este teste roda na suíte `dominio`: sem container, sem kernel,
 * sem banco. É consequência de o `Usuario` não implementar `UserInterface` —
 * as regras de quem-pode-o-quê ficaram testáveis em microssegundos.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class AutorizacaoTest extends TestCase
{
    private function hash(): HashDeSenha
    {
        // Hash de teste: o algoritmo de verdade é exercitado em produção, e
        // aqui só atrapalharia o tempo da suíte.
        return new class implements HashDeSenha {
            public function gerar(string $senhaEmTextoPuro): string
            {
                return 'hash:'.$senhaEmTextoPuro;
            }

            public function confere(string $senhaEmTextoPuro, string $hash): bool
            {
                return $hash === 'hash:'.$senhaEmTextoPuro;
            }
        };
    }

    /**
     * @param list<Papel> $papeis
     */
    private function usuario(string $id, array $papeis): Usuario
    {
        return Usuario::cadastrar(
            new UsuarioId($id),
            sprintf('%s@email.com', $id),
            'senha-bem-longa',
            'Fulano',
            $this->hash(),
            new \DateTimeImmutable('2026-07-01 10:00:00'),
            $papeis,
        );
    }

    private function eventoDe(Usuario $dono): Evento
    {
        return Evento::criar(
            new EventoId('evento-1'),
            $dono->id,
            'FrontZ Conf 2026',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('2026-09-12 09:00:00'),
        );
    }

    // ── o caso que importa ───────────────────────────────────────────────

    #[Test]
    #[TestDox('ADR-004: organizador com papel correto NÃO alcança evento alheio')]
    public function organizadorNaoAlcancaEventoDeOutro(): void
    {
        $rafael = $this->usuario('rafael', [Papel::ORGANIZADOR]);
        $joana = $this->usuario('joana', [Papel::ORGANIZADOR]);

        $eventoDaJoana = $this->eventoDe($joana);

        // Rafael tem o papel. Tem token válido. E mesmo assim:
        self::assertTrue($rafael->ehOrganizador(), 'Rafael É organizador.');
        self::assertFalse(
            $eventoDaJoana->pertenceA($rafael->id),
            'Rafael não pode alcançar o evento da Joana — papel não basta.',
        );

        // A dona alcança o próprio.
        self::assertTrue($eventoDaJoana->pertenceA($joana->id));
    }

    #[Test]
    #[TestDox('comprador não vira organizador por tentar')]
    public function compradorNaoEOrganizador(): void
    {
        $ana = $this->usuario('ana', [Papel::COMPRADOR]);
        $evento = $this->eventoDe($this->usuario('rafael', [Papel::ORGANIZADOR]));

        self::assertFalse($ana->ehOrganizador());
        self::assertFalse($evento->pertenceA($ana->id));
    }

    #[Test]
    #[TestDox('ter o vínculo sem o papel também não basta')]
    public function vinculoSemPapelNaoBasta(): void
    {
        // Caso patológico mas possível: o papel foi revogado e o evento
        // continua apontando para a pessoa. As duas condições são necessárias,
        // e é por isso que o Voter usa `&&` e não `||`.
        $rafael = $this->usuario('rafael', [Papel::ORGANIZADOR]);
        $evento = $this->eventoDe($rafael);

        $rafael->revogar(Papel::ORGANIZADOR);

        self::assertTrue($evento->pertenceA($rafael->id), 'O vínculo continua.');
        self::assertFalse($rafael->ehOrganizador(), 'Mas o papel não.');
    }

    // ── papéis acumuláveis ───────────────────────────────────────────────

    #[Test]
    #[TestDox('ADR-004: papéis são acumuláveis — organizador também confere a porta')]
    public function papeisSaoAcumulaveis(): void
    {
        $rafael = $this->usuario('rafael', [Papel::ORGANIZADOR, Papel::PORTARIA]);

        self::assertTrue($rafael->ehOrganizador());
        self::assertTrue($rafael->ehPortaria());
        self::assertSame(
            ['ROLE_ORGANIZADOR', 'ROLE_PORTARIA'],
            $rafael->papeisComoTexto(),
        );
    }

    #[Test]
    #[TestDox('conceder o mesmo papel duas vezes não o duplica')]
    public function concederEIdempotente(): void
    {
        $ana = $this->usuario('ana', [Papel::COMPRADOR]);

        $ana->conceder(Papel::ORGANIZADOR);
        $ana->conceder(Papel::ORGANIZADOR);

        self::assertCount(2, $ana->papeis());
    }

    #[Test]
    #[TestDox('todo usuário nasce comprador')]
    public function nasceComprador(): void
    {
        $ana = Usuario::cadastrar(
            new UsuarioId('ana'),
            'ana@email.com',
            'senha-bem-longa',
            'Ana',
            $this->hash(),
            new \DateTimeImmutable(),
        );

        self::assertTrue($ana->tem(Papel::COMPRADOR));
    }

    // ── credenciais ──────────────────────────────────────────────────────

    #[Test]
    #[TestDox('senha errada e e-mail inexistente dão o MESMO erro')]
    public function erroDeCredencialNaoDistingueOsCasos(): void
    {
        $ana = $this->usuario('ana', [Papel::COMPRADOR]);

        try {
            $ana->autenticarCom('senha-errada-mas-longa', $this->hash());
            self::fail('Deveria ter recusado.');
        } catch (\Lugar\Domain\Usuario\Excecao\CredenciaisInvalidas $erro) {
            // Mensagens distintas para "e-mail não existe" e "senha errada"
            // entregam de graça quais e-mails estão cadastrados. É assim que
            // se enumera a base de usuários de um sistema.
            self::assertSame('E-mail ou senha incorretos.', $erro->getMessage());
            self::assertStringNotContainsStringIgnoringCase('senha', $erro->tipo());
        }
    }

    #[Test]
    #[TestDox('senha curta é recusada no cadastro')]
    public function senhaCurtaERecusada(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Usuario::cadastrar(
            new UsuarioId('x'),
            'x@email.com',
            'curta',
            'X',
            $this->hash(),
            new \DateTimeImmutable(),
        );
    }

    #[Test]
    #[TestDox('o e-mail é normalizado para minúsculas')]
    public function emailENormalizado(): void
    {
        $usuario = Usuario::cadastrar(
            new UsuarioId('x'),
            '  ANA@Email.COM  ',
            'senha-bem-longa',
            'Ana',
            $this->hash(),
            new \DateTimeImmutable(),
        );

        // Sem isso, "Ana@email.com" e "ana@email.com" seriam contas distintas
        // — e a segunda pessoa acharia que perdeu a conta.
        self::assertSame('ana@email.com', $usuario->email);
    }
}
