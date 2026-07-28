<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Organizador;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
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
 * ═══════════════════════════════════════════════════════════════════════════
 * O SEGUNDO TESTE MAIS IMPORTANTE DO REPOSITÓRIO
 *
 * O PLAN.md (§5, fase 3) diz isso com todas as letras, e o critério de pronto
 * da fase 6 repete: "organizador A não consegue ver, editar nem exportar nada
 * do organizador B — provado por teste, não por inspeção".
 *
 * O caso que importa não é o que quase nunca falha — comprador sem papel
 * levando 403. É este:
 *
 *   Rafael tem conta. Tem ROLE_ORGANIZADOR. Tem um access token VÁLIDO, emitido
 *   pelo login de verdade. E pede o painel do evento da Joana.
 *
 * Um sistema que confere só o papel devolve 200 com o faturamento dela. E o bug
 * não aparece em tela nenhuma, porque o front nunca oferece esse link — mas a
 * API não sabe o que o front oferece, e quem chama a API não passa pelo front.
 *
 * POR QUE ESTE TESTE SOBE O HTTP INTEIRO
 *
 * `tests/Dominio/Usuario/AutorizacaoTest.php` já prova a regra no domínio, em
 * microssegundos. Ele prova que `Evento::pertenceA()` responde certo — não
 * prova que alguém a CHAMA. Entre a regra correta e a rota protegida existem o
 * firewall, o `access_control`, o Voter e uma linha do controller, e é
 * justamente essa linha que se apaga sem quebrar nenhum teste de unidade.
 *
 * Por isso aqui vai requisição HTTP de verdade, com token de verdade, contra o
 * kernel inteiro. É o único jeito de o teste ficar vermelho quando a proteção
 * sumir.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PainelAutorizacaoTest extends WebTestCase
{
    private const string SENHA = 'senha-bem-longa';
    private const string EVENTO_DA_JOANA = 'evento-da-joana';
    private const string EVENTO_DO_RAFAEL = 'evento-do-rafael';
    private const int PRECO_CENTAVOS = 200_00;
    private const int VENDIDOS = 30;
    private const int CAPACIDADE = 100;

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

    // ── o caso que importa ───────────────────────────────────────────────

    #[Test]
    #[TestDox('ADR-004: organizador com token VÁLIDO recebe 403 no painel de evento alheio')]
    public function organizadorNaoLeOPainelDeOutro(): void
    {
        $this->pedirPainel(self::EVENTO_DA_JOANA, $this->entrarComo('rafael@lugar.test'));

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->cliente->getResponse()->getStatusCode(),
            'Rafael é organizador e está autenticado — e mesmo assim o painel da '
            .'Joana não é dele. Se este teste virar 200, a chamada ao EventoVoter '
            .'saiu do OrganizadorController.',
        );

        // E o corpo não pode vazar por acidente o que a rota recusou entregar.
        $corpo = (string) $this->cliente->getResponse()->getContent();

        self::assertStringNotContainsString('Joana', $corpo);
        self::assertStringNotContainsString('receitaConfirmada', $corpo);
    }

    #[Test]
    #[TestDox('a dona do evento alcança o próprio painel')]
    public function aDonaAlcancaOProprioPainel(): void
    {
        // O contraste é a metade que falta: uma rota que respondesse 403 sempre
        // passaria no teste acima, e teria quebrado o produto sem quebrar a
        // suíte. Provar quem NÃO passa exige provar quem passa.
        $this->pedirPainel(self::EVENTO_DA_JOANA, $this->entrarComo('joana@lugar.test'));

        self::assertResponseIsSuccessful();

        $painel = $this->corpoJson();

        self::assertSame(
            self::EVENTO_DA_JOANA,
            $this->texto($this->mapa($painel['evento'] ?? null)['id'] ?? null),
        );
        self::assertArrayHasKey('receitaConfirmada', $painel);
        self::assertArrayHasKey('compradores', $painel);
    }

    #[Test]
    #[TestDox('comprador sem ROLE_ORGANIZADOR não passa nem do access_control')]
    public function compradorNaoAlcancaPainelNenhum(): void
    {
        $this->pedirPainel(self::EVENTO_DO_RAFAEL, $this->entrarComo('ana@lugar.test'));

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->cliente->getResponse()->getStatusCode(),
        );
    }

    #[Test]
    #[TestDox('sem token nenhum a rota responde 401, e não 403')]
    public function semTokenNaoHaPainel(): void
    {
        // A distinção importa: 401 diz "identifique-se" e 403 diz "identifiquei
        // você, e não pode". Trocar um pelo outro manda a pessoa logada tentar
        // logar de novo, para sempre.
        $this->cliente->request(
            'GET',
            sprintf('/api/organizador/eventos/%s/painel', self::EVENTO_DO_RAFAEL),
        );

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->cliente->getResponse()->getStatusCode(),
        );
    }

    #[Test]
    #[TestDox('a lista de eventos sai do token, e não de parâmetro da requisição')]
    public function aListaSoTrazOsProprios(): void
    {
        // O `?organizadorId=` é o ataque óbvio: se a rota o lesse, bastaria
        // trocar o valor para abrir a agenda de qualquer um.
        $this->cliente->request(
            'GET',
            '/api/organizador/eventos?organizadorId=joana',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->entrarComo('rafael@lugar.test')],
        );

        self::assertResponseIsSuccessful();

        $ids = array_map(
            fn (mixed $item): string => $this->texto($this->mapa($item)['id'] ?? null),
            $this->lista($this->corpoJson()['itens'] ?? null),
        );

        self::assertSame([self::EVENTO_DO_RAFAEL], $ids);
    }

    #[Test]
    #[TestDox('evento inexistente é 404, e não 403 disfarçado')]
    public function eventoInexistenteE404(): void
    {
        $this->pedirPainel('evento-que-nao-existe', $this->entrarComo('rafael@lugar.test'));

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->cliente->getResponse()->getStatusCode(),
        );
    }

    // ── os números do painel ─────────────────────────────────────────────

    #[Test]
    #[TestDox('uma reserva PENDENTE segura estoque no painel, pela regra do ADR-002')]
    public function reservaPendenteSeguraEstoque(): void
    {
        $this->reservaPendente('bruno@lugar.test', 2);

        $this->pedirPainel(self::EVENTO_DO_RAFAEL, $this->entrarComo('rafael@lugar.test'));

        self::assertResponseIsSuccessful();

        $painel = $this->corpoJson();

        self::assertSame(2, $this->inteiro($painel['reservadosAgora'] ?? null));

        // O ponto do teste: o disponível do painel desconta a reserva ativa,
        // igual ao que o checkout enxerga sob o lock. Se o painel somasse só
        // total − vendidos, mostraria 70 e ofereceria dois lugares que já têm
        // dono por dez minutos.
        self::assertSame(
            self::CAPACIDADE - self::VENDIDOS - 2,
            $this->inteiro($painel['disponiveis'] ?? null),
            'O painel e o checkout precisam ler o MESMO estoque (ADR-002).',
        );
    }

    #[Test]
    #[TestDox('receita e conversão saem das reservas, não do contador do lote')]
    public function receitaEConversaoSaemDasReservas(): void
    {
        /*
          Três desfechos: duas viraram venda, uma expirou.

          A receita NÃO é `vendidos × preço atual`. O preço do lote muda com o
          tempo, e quem comprou no 1º lote pagou o preço do 1º lote — recalcular
          pelo preço de hoje reescreveria o passado. Cada reserva guarda o total
          que foi efetivamente cobrado, e é dele que a soma sai.
        */
        $this->reservaConfirmada('carla@lugar.test', 2);
        $this->reservaConfirmada('diego@lugar.test', 1);
        $this->reservaExpirada('elisa@lugar.test', 4);

        $this->pedirPainel(self::EVENTO_DO_RAFAEL, $this->entrarComo('rafael@lugar.test'));

        self::assertResponseIsSuccessful();

        $painel = $this->corpoJson();

        self::assertSame(
            3 * self::PRECO_CENTAVOS,
            $this->inteiro($this->mapa($painel['receitaConfirmada'] ?? null)['centavos'] ?? null),
        );
        self::assertSame(2, $this->inteiro($painel['vendasConfirmadas'] ?? null));

        // 2 de 3 desfechos viraram venda. A reserva expirada entra no
        // denominador — é justamente ela que a métrica existe para medir.
        $conversao = $this->mapa($painel['conversao'] ?? null);

        self::assertSame(67, $this->inteiro($conversao['viraramVenda'] ?? null));
        self::assertSame(
            33,
            $this->inteiro($conversao['expiraram'] ?? null),
            'Os dois lados somam 100: a barra da tela pinta um e deixa o resto.',
        );

        // A reserva expirada devolveu o estoque — não segura mais nada.
        self::assertSame(0, $this->inteiro($painel['reservadosAgora'] ?? null));
    }

    #[Test]
    #[TestDox('sem nenhum desfecho, a conversão é 0 — e não divisão por zero')]
    public function conversaoSemDesfechoENula(): void
    {
        $this->pedirPainel(self::EVENTO_DO_RAFAEL, $this->entrarComo('rafael@lugar.test'));

        self::assertResponseIsSuccessful();

        $painel = $this->corpoJson();

        self::assertSame(
            0,
            $this->inteiro($this->mapa($painel['conversao'] ?? null)['viraramVenda'] ?? null),
        );
        self::assertSame(self::VENDIDOS, $this->inteiro($painel['vendidos'] ?? null));
        self::assertSame(
            self::CAPACIDADE - self::VENDIDOS,
            $this->inteiro($painel['disponiveis'] ?? null),
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    /**
     * Login de verdade, pela rota de verdade. Fabricar o JWT direto pelo emissor
     * pularia o firewall — que é metade do que este arquivo verifica.
     */
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

    private function pedirPainel(string $eventoId, string $token): void
    {
        $this->cliente->request(
            'GET',
            sprintf('/api/organizador/eventos/%s/painel', $eventoId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
    }

    // ── leitura tipada do corpo ──────────────────────────────────────────
    /*
      O PHPStan roda em nível 9 sobre `tests/` também, e o projeto não instala a
      extensão do PHPUnit — `assertIsString` não estreita tipo para o analisador.
      Estes três ajudantes fazem o estreitamento de verdade, com `self::fail()`,
      que é `never`: o teste falha com uma mensagem útil em vez de estourar um
      TypeError três linhas adiante.
    */

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

    /**
     * @return array<array-key, mixed>
     */
    private function mapa(mixed $valor): array
    {
        if (!\is_array($valor)) {
            self::fail('Esperava um objeto no corpo da resposta.');
        }

        return $valor;
    }

    /**
     * @return list<mixed>
     */
    private function lista(mixed $valor): array
    {
        if (!\is_array($valor)) {
            self::fail('Esperava uma lista no corpo da resposta.');
        }

        return array_values($valor);
    }

    private function texto(mixed $valor): string
    {
        if (!\is_string($valor)) {
            self::fail('Esperava texto no corpo da resposta.');
        }

        return $valor;
    }

    private function inteiro(mixed $valor): int
    {
        if (!\is_int($valor)) {
            self::fail('Esperava um número inteiro no corpo da resposta.');
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

        $this->criarEvento(self::EVENTO_DO_RAFAEL, $rafael);
        $this->criarEvento(self::EVENTO_DA_JOANA, $joana);

        $this->servico(EntityManagerInterface::class)->clear();
    }

    private function criarEvento(string $id, Usuario $dono): void
    {
        $evento = Evento::criar(
            new EventoId($id),
            $dono->id,
            sprintf('Evento de %s', $dono->nome()),
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $evento->publicar();

        $this->servico(RepositorioDeEventos::class)->salvar($evento);

        $this->servico(RepositorioDeLotes::class)->salvar(new Lote(
            new LoteId($id.'-lote'),
            $evento->id,
            'Lote único',
            Dinheiro::emCentavos(self::PRECO_CENTAVOS),
            self::CAPACIDADE,
            self::VENDIDOS,
            Periodo::de(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+29 days')),
        ));
    }

    /*
      As reservas do cenário. Não passam pelo caso de uso `CriarReserva` de
      propósito: o que se testa aqui é a LEITURA do painel, e montar o estado
      final direto é mais rápido e mais legível que encenar o caminho até ele.
      Quem prova que o caminho funciona é o teste de concorrência.
    */

    private function reservaPendente(string $email, int $quantidade): Reserva
    {
        return $this->salvarReserva($this->novaReserva($email, $quantidade));
    }

    private function reservaConfirmada(string $email, int $quantidade): void
    {
        $reserva = $this->novaReserva($email, $quantidade);
        $reserva->confirmar(new \DateTimeImmutable());

        $this->salvarReserva($reserva);
    }

    private function reservaExpirada(string $email, int $quantidade): void
    {
        $reserva = $this->novaReserva($email, $quantidade);
        // `marcarComoExpirada` recusa reserva ainda válida, então o "agora"
        // vai para o futuro — mais honesto que mexer no relógio do container.
        $reserva->marcarComoExpirada(new \DateTimeImmutable('+1 hour'));

        $this->salvarReserva($reserva);
    }

    private function novaReserva(string $email, int $quantidade): Reserva
    {
        return Reserva::criar(
            new ReservaId('reserva-'.md5($email)),
            new LoteId(self::EVENTO_DO_RAFAEL.'-lote'),
            $email,
            $quantidade,
            Dinheiro::emCentavos($quantidade * self::PRECO_CENTAVOS),
            new \DateTimeImmutable(),
        );
    }

    private function salvarReserva(Reserva $reserva): Reserva
    {
        $this->servico(RepositorioDeReservas::class)->salvar($reserva);
        $this->servico(EntityManagerInterface::class)->clear();

        return $reserva;
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

        // A ordem segue as chaves estrangeiras: filho antes de pai. `pagamento`
        // aponta para `reserva`, então vem antes dela — esquecer isso faz a
        // limpeza estourar com violação de FK assim que qualquer teste da
        // suíte gravar um pagamento.
        foreach (['pagamento', 'token_de_renovacao', 'evento_operador', 'ingresso', 'reserva', 'lote', 'evento', 'usuario'] as $tabela) {
            $conexao->executeStatement(sprintf('DELETE FROM %s', $tabela));
        }
    }
}
