<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Organizador;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
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
 * Fase 6.1 pela superfície HTTP: criar, publicar e excluir evento.
 *
 * As três coisas que este arquivo prova e nenhum teste de domínio consegue:
 *
 *   1. O evento nasce em RASCUNHO e NÃO aparece na vitrine até ser publicado
 *      — o ciclo inteiro, atravessando controller, caso de uso e consulta.
 *   2. RN-12 na prática: excluir evento com venda confirmada responde 409
 *      com `type` acionável, e o evento continua existindo depois da recusa.
 *   3. A posse (ADR-004): token VÁLIDO de organizador não publica evento
 *      alheio. O papel passa no access_control; quem barra é o EventoVoter.
 */
final class GestaoDeEventosTest extends WebTestCase
{
    private const string SENHA = 'senha-bem-longa';
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

    // ── criar e publicar ─────────────────────────────────────────────────

    #[Test]
    #[TestDox('o evento nasce em rascunho, fora da vitrine, e a publicação o expõe')]
    public function cicloCriarPublicar(): void
    {
        $token = $this->entrarComo('rafael@lugar.test');

        $id = $this->criarEventoPorHttp($token);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('RASCUNHO', $this->corpoJson()['status'] ?? null);
        self::assertNotContains($id, $this->idsDaVitrine(), 'Rascunho não aparece na vitrine.');

        $this->cliente->request(
            'POST',
            sprintf('/api/eventos/%s/publicar', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('PUBLICADO', $this->corpoJson()['status'] ?? null);
        self::assertContains($id, $this->idsDaVitrine(), 'Publicado tem que estar na vitrine.');
    }

    #[Test]
    #[TestDox('comprador com token válido não cria evento — 403 do access_control')]
    public function compradorNaoCriaEvento(): void
    {
        $this->criarEventoPorHttp($this->entrarComo('ana@lugar.test'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[TestDox('sem token, criar evento responde 401, e não 403')]
    public function semTokenNaoHaCriacao(): void
    {
        $this->cliente->request(
            'POST',
            '/api/eventos',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    #[TestDox('ADR-004: organizador não publica evento de outro — 403 do EventoVoter')]
    public function organizadorNaoPublicaEventoAlheio(): void
    {
        $this->cliente->request(
            'POST',
            sprintf('/api/eventos/%s/publicar', self::EVENTO_DA_JOANA),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->entrarComo('rafael@lugar.test')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[TestDox('evento sem lote não vai à vitrine — 409 type=evento-sem-lote')]
    public function eventoSemLoteNaoPublica(): void
    {
        // Direto pelo repositório: a rota de criação recusa lote vazio, mas o
        // dado pode existir por outros caminhos — e a publicação é a última
        // porta antes da vitrine.
        $rafael = $this->usuarioDe('rafael@lugar.test');
        $pelado = Evento::criar(
            new EventoId('evento-sem-lote'),
            $rafael->id,
            'Evento sem lote',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $this->servico(RepositorioDeEventos::class)->salvar($pelado);
        $this->servico(EntityManagerInterface::class)->clear();

        $this->cliente->request(
            'POST',
            '/api/eventos/evento-sem-lote/publicar',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->entrarComo('rafael@lugar.test')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('evento-sem-lote', $this->texto($this->corpoJson()['type'] ?? null));
    }

    // ── RN-12 ────────────────────────────────────────────────────────────

    #[Test]
    #[TestDox('RN-12: evento com venda confirmada não some — 409 type=evento-com-vendas')]
    public function eventoComVendaNaoEExcluido(): void
    {
        $token = $this->entrarComo('rafael@lugar.test');
        $id = $this->criarEventoPorHttp($token);
        $this->confirmarUmaVendaNo($id);

        $this->cliente->request(
            'DELETE',
            sprintf('/api/eventos/%s', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('evento-com-vendas', $this->texto($this->corpoJson()['type'] ?? null));

        // A recusa precisa ter sido ANTES de qualquer DELETE: o evento segue lá.
        $this->cliente->request(
            'GET',
            sprintf('/api/organizador/eventos/%s/painel', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[TestDox('rascunho sem venda é excluído de verdade, com os lotes junto')]
    public function rascunhoSomeDaBase(): void
    {
        $token = $this->entrarComo('rafael@lugar.test');
        $id = $this->criarEventoPorHttp($token);

        $this->cliente->request(
            'DELETE',
            sprintf('/api/eventos/%s', $id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $sobras = $this->servico(Connection::class)->executeQuery(
            'SELECT (SELECT COUNT(*) FROM evento WHERE id = :id) + (SELECT COUNT(*) FROM lote WHERE evento_id = :id)',
            ['id' => $id],
        )->fetchOne();

        self::assertSame(0, (int) (is_numeric($sobras) ? $sobras : -1));
    }

    #[Test]
    #[TestDox('organizador não exclui evento de outro — 403 antes de qualquer regra')]
    public function organizadorNaoExcluiEventoAlheio(): void
    {
        $this->cliente->request(
            'DELETE',
            sprintf('/api/eventos/%s', self::EVENTO_DA_JOANA),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->entrarComo('rafael@lugar.test')],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    private function criarEventoPorHttp(string $token): string
    {
        $this->cliente->request(
            'POST',
            '/api/eventos',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'titulo' => 'Meetup de Engenharia — edição 12',
                'local' => 'Teatro B32',
                'cidade' => 'São Paulo',
                'iniciaEm' => (new \DateTimeImmutable('+45 days'))->format(\DATE_ATOM),
                'descricao' => 'Um sábado que vale o sábado.',
                'prazoReservaMinutos' => 15,
                'lotes' => [
                    ['nome' => '1º lote', 'precoCentavos' => 18000, 'quantidade' => 200],
                    ['nome' => '2º lote', 'precoCentavos' => 22000, 'quantidade' => 310],
                ],
            ], \JSON_THROW_ON_ERROR),
        );

        $corpo = $this->corpoJson();

        return $this->texto($corpo['id'] ?? '');
    }

    /**
     * Monta o estado "vendeu" direto pelos repositórios: uma reserva
     * CONFIRMADA num lote do evento. O caminho até esse estado é provado
     * pelo WebhookTest; aqui ele é só o cenário da RN-12.
     */
    private function confirmarUmaVendaNo(string $eventoId): void
    {
        $lotes = $this->servico(RepositorioDeLotes::class)->doEvento(new EventoId($eventoId));
        self::assertNotSame([], $lotes, 'O cenário precisa de um lote para vender.');

        $reserva = Reserva::criar(
            new ReservaId('venda-'.$eventoId),
            $lotes[0]->id,
            'carla@lugar.test',
            1,
            Dinheiro::emCentavos(18000),
            new \DateTimeImmutable(),
        );
        $reserva->confirmar(new \DateTimeImmutable());

        $this->servico(RepositorioDeReservas::class)->salvar($reserva);
        $this->servico(EntityManagerInterface::class)->clear();
    }

    /**
     * @return list<string>
     */
    private function idsDaVitrine(): array
    {
        $this->cliente->request('GET', '/api/eventos');
        self::assertResponseIsSuccessful();

        $itens = $this->corpoJson()['itens'] ?? null;

        if (!\is_array($itens)) {
            self::fail('A vitrine deveria devolver uma lista.');
        }

        return array_values(array_map(
            fn (mixed $item): string => $this->texto(\is_array($item) ? ($item['id'] ?? null) : null),
            $itens,
        ));
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

    private function usuarioDe(string $email): Usuario
    {
        $usuario = $this->servico(RepositorioDeUsuarios::class)->buscarPorEmail($email);

        if (null === $usuario) {
            self::fail(sprintf('O cenário deveria ter criado %s.', $email));
        }

        return $usuario;
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
        $ana = $this->usuario('ana', 'ana@lugar.test', 'Ana Souza', [Papel::COMPRADOR], $hash);

        foreach ([$rafael, $joana, $ana] as $usuario) {
            $usuarios->salvar($usuario);
        }

        $daJoana = Evento::criar(
            new EventoId(self::EVENTO_DA_JOANA),
            $joana->id,
            'Evento de Joana',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $this->servico(RepositorioDeEventos::class)->salvar($daJoana);

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
