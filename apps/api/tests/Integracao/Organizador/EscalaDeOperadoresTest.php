<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Organizador;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;
use Lugar\Domain\Usuario\UsuarioId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 6.4 — a escala da portaria, de ponta a ponta.
 *
 * O que se prova aqui não é o INSERT em `evento_operador`; é a CONSEQUÊNCIA:
 * escalar abre exatamente UMA porta, e retirar a fecha. O termômetro é o
 * `GET /api/portaria/{eventoId}` — a tela que o porteiro abre —, que passa
 * pelo PortariaVoter como toda validação de ingresso.
 *
 * A escala é a terceira célula em itálico da matriz do PLAN.md §4: papel não
 * basta, é preciso vínculo. Pedro TEM o papel de portaria depois de escalado
 * — e mesmo assim não valida no evento da Joana.
 */
final class EscalaDeOperadoresTest extends WebTestCase
{
    private const string SENHA = 'senha-bem-longa';
    private const string EVENTO_DO_RAFAEL = 'evento-do-rafael';
    private const string EVENTO_DA_JOANA = 'evento-da-joana';

    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = self::createClient();
        $this->limparBase();
        $this->montarCenario();
    }

    protected function tearDown(): void
    {
        $this->limparBase();
        parent::tearDown();
    }

    #[Test]
    #[TestDox('escalar abre a porta de UM evento — e só dele')]
    public function escalarAbreUmaPortaSo(): void
    {
        $this->escalar(self::EVENTO_DO_RAFAEL, 'pedro@lugar.test', $this->entrarComo('rafael@lugar.test'));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // A conta do Pedro nasceu como COMPRADOR; a escala concedeu o papel.
        // Login DEPOIS de escalar, para o token carregar os papéis novos.
        $tokenDePedro = $this->entrarComo('pedro@lugar.test');

        $this->abrirPorta(self::EVENTO_DO_RAFAEL, $tokenDePedro);
        self::assertResponseIsSuccessful('Escalado no evento, Pedro trabalha nesta porta.');

        $this->abrirPorta(self::EVENTO_DA_JOANA, $tokenDePedro);
        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
            'O papel de portaria NÃO abre a porta onde Pedro não está escalado (ADR-004).',
        );
    }

    #[Test]
    #[TestDox('retirar da escala fecha a porta que o escalado tinha')]
    public function retirarFechaAPorta(): void
    {
        $tokenDoRafael = $this->entrarComo('rafael@lugar.test');
        $this->escalar(self::EVENTO_DO_RAFAEL, 'pedro@lugar.test', $tokenDoRafael);

        $pedroId = $this->texto($this->corpoJson()['id'] ?? null);
        $tokenDePedro = $this->entrarComo('pedro@lugar.test');

        $this->abrirPorta(self::EVENTO_DO_RAFAEL, $tokenDePedro);
        self::assertResponseIsSuccessful();

        $this->cliente->request(
            'DELETE',
            sprintf('/api/organizador/eventos/%s/operadores/%s', self::EVENTO_DO_RAFAEL, $pedroId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenDoRafael],
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->abrirPorta(self::EVENTO_DO_RAFAEL, $tokenDePedro);
        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
            'Fora da escala, o papel sozinho não abre porta nenhuma.',
        );
    }

    #[Test]
    #[TestDox('a lista de operadores mostra quem está escalado, com nome e e-mail')]
    public function listaDeOperadores(): void
    {
        $token = $this->entrarComo('rafael@lugar.test');
        $this->escalar(self::EVENTO_DO_RAFAEL, 'pedro@lugar.test', $token);

        $this->cliente->request(
            'GET',
            sprintf('/api/organizador/eventos/%s/operadores', self::EVENTO_DO_RAFAEL),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        self::assertResponseIsSuccessful();

        $itens = $this->corpoJson()['itens'] ?? null;

        if (!\is_array($itens)) {
            self::fail('A lista de operadores deveria ser uma lista.');
        }

        self::assertCount(1, $itens);
        $operador = \is_array($itens[0]) ? $itens[0] : [];
        self::assertSame('pedro@lugar.test', $operador['email'] ?? null);
        self::assertSame('Pedro Lima', $operador['nome'] ?? null);
    }

    #[Test]
    #[TestDox('ADR-004: organizador não escala operador em evento alheio — 403')]
    public function organizadorNaoEscalaEmEventoAlheio(): void
    {
        $this->escalar(self::EVENTO_DA_JOANA, 'pedro@lugar.test', $this->entrarComo('rafael@lugar.test'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[TestDox('e-mail sem conta não entra na escala — 404 type=operador-desconhecido')]
    public function emailSemContaNaoEscala(): void
    {
        $this->escalar(self::EVENTO_DO_RAFAEL, 'ninguem@lugar.test', $this->entrarComo('rafael@lugar.test'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringContainsString(
            'operador-desconhecido',
            $this->texto($this->corpoJson()['type'] ?? null),
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    private function escalar(string $eventoId, string $email, string $token): void
    {
        $this->cliente->request(
            'POST',
            sprintf('/api/organizador/eventos/%s/operadores', $eventoId),
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );
    }

    private function abrirPorta(string $eventoId, string $token): void
    {
        $this->cliente->request(
            'GET',
            sprintf('/api/portaria/%s', $eventoId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
    }

    private function entrarComo(string $email): string
    {
        $this->cliente->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'senha' => self::SENHA], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful(sprintf('O login de %s deveria funcionar.', $email));

        return $this->texto($this->corpoJson()['accessToken'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function corpoJson(): array
    {
        $corpo = json_decode(
            (string) $this->cliente->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        if (!\is_array($corpo)) {
            self::fail('A resposta não era um objeto JSON.');
        }

        return $corpo;
    }

    private function texto(mixed $valor): string
    {
        if (!\is_string($valor)) {
            self::fail('Esperava texto no corpo da resposta.');
        }

        return $valor;
    }

    // ── cenário ──────────────────────────────────────────────────────────

    private function montarCenario(): void
    {
        $usuarios = $this->servico(RepositorioDeUsuarios::class);
        $hash = $this->servico(HashDeSenha::class);

        $rafael = $this->usuario('rafael', 'rafael@lugar.test', 'Rafael Mendes', [Papel::COMPRADOR, Papel::ORGANIZADOR], $hash);
        $joana = $this->usuario('joana', 'joana@lugar.test', 'Joana Prado', [Papel::COMPRADOR, Papel::ORGANIZADOR], $hash);
        // Pedro NÃO nasce com papel de portaria: é a escala que o concede.
        $pedro = $this->usuario('pedro', 'pedro@lugar.test', 'Pedro Lima', [Papel::COMPRADOR], $hash);

        foreach ([$rafael, $joana, $pedro] as $usuario) {
            $usuarios->salvar($usuario);
        }

        $eventos = $this->servico(RepositorioDeEventos::class);

        foreach ([self::EVENTO_DO_RAFAEL => $rafael, self::EVENTO_DA_JOANA => $joana] as $id => $dono) {
            $evento = Evento::criar(
                new EventoId($id),
                $dono->id,
                sprintf('Evento de %s', $dono->nome()),
                'Teatro B32',
                'São Paulo',
                new \DateTimeImmutable('+30 days'),
            );
            $evento->publicar();
            $eventos->salvar($evento);
        }

        $this->servico(EntityManagerInterface::class)->clear();
    }

    /**
     * @param list<Papel> $papeis
     */
    private function usuario(string $id, string $email, string $nome, array $papeis, HashDeSenha $hash): Usuario
    {
        return Usuario::cadastrar(
            new UsuarioId($id),
            $email,
            self::SENHA,
            $nome,
            $hash,
            new \DateTimeImmutable(),
            $papeis,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function servico(string $classe): object
    {
        $servico = self::getContainer()->get($classe);

        if (!$servico instanceof $classe) {
            self::fail(sprintf('O serviço %s não está disponível no container de teste.', $classe));
        }

        return $servico;
    }

    private function limparBase(): void
    {
        $conexao = $this->servico(Connection::class);

        foreach (['pagamento', 'token_de_renovacao', 'evento_operador', 'ingresso', 'reserva', 'lote', 'evento', 'usuario'] as $tabela) {
            $conexao->executeStatement(sprintf('DELETE FROM %s', $tabela));
        }
    }
}
