<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Pagamento;

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
use Lugar\Infrastructure\Pagamento\AssinaturaHmac;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * O CRITÉRIO DE PRONTO DA FASE 5
 *
 * PLAN.md, fase 5: "reprocessar o mesmo webhook três vezes não duplica
 * ingresso, e existe teste provando isso".
 *
 * É este arquivo, e o teste está escrito com TRÊS entregas de propósito — não
 * duas. Duas provariam que existe um `if` em algum lugar. Três, contra o banco
 * real, provam que o efeito é estável: o `UNIQUE (provedor_id)` continua
 * segurando depois que a primeira gravação já commitou, e o caminho de
 * reentrega não emite nada nem estoura.
 *
 * O SEGUNDO ASSUNTO DESTE ARQUIVO É A ASSINATURA
 *
 * `/api/webhooks/pagamento` é público, não tem sessão e manda emitir ingresso.
 * A única coisa entre ele e a internet é o HMAC. Se os casos de recusa daqui
 * virarem verde por engano — assinatura errada, corpo adulterado, cabeçalho
 * ausente —, qualquer pessoa emite ingresso de graça mandando um JSON.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class WebhookTest extends WebTestCase
{
    private const string SEGREDO = 'segredo_apenas_de_desenvolvimento';
    private const string EVENTO_ID = 'evento-pagamento';
    private const string LOTE_ID = 'lote-pagamento';
    private const int PRECO_CENTAVOS = 220_00;
    private const int QUANTIDADE = 3;

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

    // ── o critério de pronto ─────────────────────────────────────────────

    #[Test]
    #[TestDox('PLAN fase 5: o MESMO webhook entregue 3x emite ingresso UMA vez')]
    public function reentregaNaoDuplicaIngresso(): void
    {
        $reserva = $this->reservaPendente();
        $corpo = $this->corpoAprovado($reserva, 'evt_reentrega');

        // Primeira entrega: emite.
        $this->entregar($corpo);

        self::assertResponseIsSuccessful();
        self::assertSame('processado', $this->campo('status'));
        self::assertSame(self::QUANTIDADE, $this->campo('ingressosEmitidos'));

        // Segunda e terceira: o gateway reenviando por precaução.
        foreach ([2, 3] as $tentativa) {
            $this->entregar($corpo);

            self::assertSame(
                Response::HTTP_OK,
                $this->cliente->getResponse()->getStatusCode(),
                sprintf(
                    'Entrega %d deveria responder 200. Erro faria o provedor '
                    .'reenviar em backoff contra um endpoint que está certo.',
                    $tentativa,
                ),
            );
            self::assertSame('ja-processado', $this->campo('status'));
        }

        // A prova está no banco, não na contagem de respostas.
        self::assertSame(
            self::QUANTIDADE,
            $this->contar('ingresso'),
            'Três entregas do mesmo evento emitiram mais de uma leva de ingressos.',
        );
        self::assertSame(1, $this->contar('pagamento'));
    }

    #[Test]
    #[TestDox('confirmar move o estoque de RESERVADO para VENDIDO, na mesma transação')]
    public function confirmarVendeOEstoque(): void
    {
        $reserva = $this->reservaPendente();

        $this->entregar($this->corpoAprovado($reserva, 'evt_estoque'));

        self::assertResponseIsSuccessful();

        /*
          O ponto sutil da fase 5.

          A query do ADR-002 conta como reservado quem está PENDENTE. Ao
          confirmar, a reserva sai dessa conta — e se `quantidade_vendida` não
          subir junto, os lugares vendidos voltariam a aparecer como
          disponíveis e o sistema revenderia o que acabou de vender.
        */
        self::assertSame(
            self::QUANTIDADE,
            $this->umInteiro('SELECT quantidade_vendida FROM lote WHERE id = :id', ['id' => self::LOTE_ID]),
            'A venda confirmada não virou estoque vendido.',
        );

        self::assertSame(
            'CONFIRMADA',
            $this->umTexto('SELECT status FROM reserva WHERE id = :id', ['id' => $reserva->id->valor]),
        );
    }

    #[Test]
    #[TestDox('RN-08: um ingresso por unidade, com códigos distintos')]
    public function emiteUmIngressoPorUnidade(): void
    {
        $reserva = $this->reservaPendente();

        $this->entregar($this->corpoAprovado($reserva, 'evt_rn08'));

        self::assertSame(self::QUANTIDADE, $this->contar('ingresso'));

        // Códigos repetidos seriam duas pessoas com direito ao mesmo lugar.
        self::assertSame(
            self::QUANTIDADE,
            $this->umInteiro('SELECT COUNT(DISTINCT codigo) FROM ingresso'),
            'Os ingressos da mesma reserva saíram com código repetido.',
        );
    }

    // ── a assinatura ─────────────────────────────────────────────────────

    #[Test]
    #[TestDox('sem assinatura o webhook responde 400 e NÃO emite nada')]
    public function semAssinaturaNaoPassa(): void
    {
        $reserva = $this->reservaPendente();

        $this->cliente->request(
            'POST',
            '/api/webhooks/pagamento',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $this->corpoAprovado($reserva, 'evt_sem_assinatura'),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->cliente->getResponse()->getStatusCode());
        self::assertSame(0, $this->contar('ingresso'));
    }

    #[Test]
    #[TestDox('assinatura de outro segredo é recusada')]
    public function segredoErradoNaoPassa(): void
    {
        $reserva = $this->reservaPendente();
        $corpo = $this->corpoAprovado($reserva, 'evt_segredo_errado');

        $this->entregar($corpo, assinatura: AssinaturaHmac::assinar($corpo, 'segredo-do-atacante', time()));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->cliente->getResponse()->getStatusCode());
        self::assertSame(0, $this->contar('ingresso'));
    }

    #[Test]
    #[TestDox('corpo adulterado depois de assinado é recusado')]
    public function corpoAdulteradoNaoPassa(): void
    {
        $reserva = $this->reservaPendente();
        $original = $this->corpoAprovado($reserva, 'evt_adulterado');

        // Assinatura legítima do corpo original...
        $assinatura = AssinaturaHmac::assinar($original, self::SEGREDO, time());

        // ...e o corpo trocado no caminho, baixando o valor para 1 centavo.
        $adulterado = str_replace(
            sprintf('"valor_centavos":%d', self::PRECO_CENTAVOS * self::QUANTIDADE),
            '"valor_centavos":1',
            $original,
        );

        self::assertNotSame($original, $adulterado, 'O corpo de teste não foi adulterado de fato.');

        $this->entregar($adulterado, assinatura: $assinatura);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->cliente->getResponse()->getStatusCode());
        self::assertSame(0, $this->contar('ingresso'));
    }

    #[Test]
    #[TestDox('assinatura velha é recusada — replay não funciona para sempre')]
    public function assinaturaForaDaJanelaNaoPassa(): void
    {
        $reserva = $this->reservaPendente();
        $corpo = $this->corpoAprovado($reserva, 'evt_replay');

        // Requisição legítima capturada há uma hora e reenviada agora. Sem a
        // janela de tempo, o HMAC continuaria conferindo para sempre.
        $this->entregar($corpo, assinatura: AssinaturaHmac::assinar($corpo, self::SEGREDO, time() - 3600));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->cliente->getResponse()->getStatusCode());
        self::assertSame(0, $this->contar('ingresso'));
    }

    // ── regras de negócio no caminho do pagamento ────────────────────────

    #[Test]
    #[TestDox('pagamento recusado registra o fato e NÃO emite ingresso')]
    public function recusaNaoEmite(): void
    {
        $reserva = $this->reservaPendente();

        $this->entregar($this->corpo($reserva, 'evt_recusado', aprovado: false));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->contar('ingresso'));
        self::assertSame(1, $this->contar('pagamento'), 'A recusa também é informação, e fica registrada.');

        // A reserva segue de pé e expira sozinha se ninguém pagar.
        self::assertSame(
            'PENDENTE',
            $this->umTexto('SELECT status FROM reserva WHERE id = :id', ['id' => $reserva->id->valor]),
        );
    }

    #[Test]
    #[TestDox('valor que não quita a reserva é recusado, mesmo com assinatura válida')]
    public function valorDivergenteNaoConfirma(): void
    {
        $reserva = $this->reservaPendente();

        /*
          A assinatura prova a ORIGEM, não que o conteúdo faça sentido para o
          nosso negócio. Confirmar uma reserva de R$ 660 com R$ 2 passaria por
          qualquer verificação criptográfica.
        */
        $corpo = json_encode([
            'id' => 'evt_valor_errado',
            'reserva_id' => $reserva->id->valor,
            'valor_centavos' => 200,
            'status' => 'aprovado',
        ], \JSON_THROW_ON_ERROR);

        $this->entregar($corpo);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->cliente->getResponse()->getStatusCode(),
        );
        self::assertSame(0, $this->contar('ingresso'));
    }

    #[Test]
    #[TestDox('o payload é guardado byte a byte, para o dia da contestação')]
    public function payloadEGuardadoIntacto(): void
    {
        $reserva = $this->reservaPendente();
        $corpo = $this->corpoAprovado($reserva, 'evt_payload');

        $this->entregar($corpo);

        self::assertResponseIsSuccessful();

        /*
          Igualdade EXATA, e não "JSON equivalente". A coluna era JSONB e foi
          migrada para TEXT justamente por isso: JSONB reordena chaves e
          descarta espaços, o que reescreveria a prova e impediria reconferir
          o HMAC sobre o que foi guardado.
        */
        self::assertSame(
            $corpo,
            $this->umTexto('SELECT payload_bruto FROM pagamento WHERE provedor_id = :id', ['id' => 'evt_payload']),
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    private function entregar(string $corpo, ?string $assinatura = null): void
    {
        $this->cliente->request(
            'POST',
            '/api/webhooks/pagamento',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_'.strtoupper(str_replace('-', '_', AssinaturaHmac::CABECALHO)) => $assinatura
                    ?? AssinaturaHmac::assinar($corpo, self::SEGREDO, time()),
            ],
            content: $corpo,
        );
    }

    private function corpoAprovado(Reserva $reserva, string $provedorId): string
    {
        return $this->corpo($reserva, $provedorId, aprovado: true);
    }

    private function corpo(Reserva $reserva, string $provedorId, bool $aprovado): string
    {
        return json_encode([
            'id' => $provedorId,
            'reserva_id' => $reserva->id->valor,
            'valor_centavos' => $reserva->total->centavos,
            'status' => $aprovado ? 'aprovado' : 'recusado',
        ], \JSON_THROW_ON_ERROR);
    }

    private function reservaPendente(): Reserva
    {
        $reserva = Reserva::criar(
            new ReservaId('reserva-do-pagamento'),
            new LoteId(self::LOTE_ID),
            'ana@lugar.test',
            self::QUANTIDADE,
            Dinheiro::emCentavos(self::PRECO_CENTAVOS * self::QUANTIDADE),
            new \DateTimeImmutable(),
        );

        $this->servico(RepositorioDeReservas::class)->salvar($reserva);
        $this->servico(EntityManagerInterface::class)->clear();

        return $reserva;
    }

    // ── leitura do corpo e do banco ──────────────────────────────────────

    private function campo(string $nome): mixed
    {
        $corpo = json_decode(
            (string) $this->cliente->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        if (!\is_array($corpo)) {
            self::fail('A resposta não era um objeto JSON.');
        }

        return $corpo[$nome] ?? null;
    }

    private function contar(string $tabela): int
    {
        return $this->umInteiro(sprintf('SELECT COUNT(*) FROM %s', $tabela));
    }

    /**
     * @param array<string, mixed> $parametros
     */
    private function umInteiro(string $sql, array $parametros = []): int
    {
        $valor = $this->servico(Connection::class)->executeQuery($sql, $parametros)->fetchOne();

        return is_numeric($valor) ? (int) $valor : 0;
    }

    /**
     * @param array<string, mixed> $parametros
     */
    private function umTexto(string $sql, array $parametros = []): string
    {
        $valor = $this->servico(Connection::class)->executeQuery($sql, $parametros)->fetchOne();

        if (!\is_string($valor)) {
            self::fail(sprintf('Esperava texto de: %s', $sql));
        }

        return $valor;
    }

    // ── cenário ──────────────────────────────────────────────────────────

    private function montarCenario(): void
    {
        $organizador = Usuario::cadastrar(
            new UsuarioId('organizador-pagamento'),
            'organizador@lugar.test',
            'senha-bem-longa',
            'Rafael Mendes',
            $this->servico(HashDeSenha::class),
            new \DateTimeImmutable(),
            [Papel::ORGANIZADOR],
        );

        $this->servico(RepositorioDeUsuarios::class)->salvar($organizador);

        $evento = Evento::criar(
            new EventoId(self::EVENTO_ID),
            $organizador->id,
            'Evento de pagamento',
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $evento->publicar();

        $this->servico(RepositorioDeEventos::class)->salvar($evento);

        $this->servico(RepositorioDeLotes::class)->salvar(new Lote(
            new LoteId(self::LOTE_ID),
            $evento->id,
            'Lote único',
            Dinheiro::emCentavos(self::PRECO_CENTAVOS),
            100,
            0,
            Periodo::de(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+29 days')),
        ));

        $this->servico(EntityManagerInterface::class)->clear();
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

        foreach (['pagamento', 'ingresso', 'reserva', 'lote', 'evento_operador', 'token_de_renovacao', 'evento', 'usuario'] as $tabela) {
            $conexao->executeStatement(sprintf('DELETE FROM %s', $tabela));
        }
    }
}
