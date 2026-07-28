<?php

declare(strict_types=1);

namespace Lugar\Tests\Integracao\Portaria;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Ingresso\CodigoIngresso;
use Lugar\Domain\Ingresso\Ingresso;
use Lugar\Domain\Ingresso\IngressoId;
use Lugar\Domain\Ingresso\RepositorioDeIngressos;
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
 * O CRITÉRIO DE PRONTO DA FASE 7
 *
 * PLAN.md: "ler o mesmo código duas vezes dá verde e depois vermelho com
 * horário, e ler um código de outro evento dá vermelho com o motivo certo".
 *
 * Os dois casos estão aqui, e mais o que o PLAN não pediu e a porta exige: a
 * escala. `ROLE_PORTARIA` diz que a pessoa trabalha em portaria; só o vínculo
 * em `evento_operador` diz em QUAL. É o mesmo argumento do painel do
 * organizador, aplicado a quem fica na porta.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class PortariaTest extends WebTestCase
{
    private const string SENHA = 'senha-bem-longa';
    private const string EVENTO_DA_PORTA = 'evento-com-porta';
    private const string OUTRO_EVENTO = 'evento-vizinho';
    private const string CODIGO = 'LGR-7Q2M-84KD';
    private const string CODIGO_VIZINHO = 'LGR-3XN9-5RTB';

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
    #[TestDox('PLAN fase 7: a MESMA leitura dá verde e depois vermelho COM horário (RN-10)')]
    public function segundaLeituraRecusaComHorario(): void
    {
        $token = $this->entrarComo('portaria@lugar.test');

        // Primeira passada: entra.
        $this->utilizar(self::CODIGO, self::EVENTO_DA_PORTA, $token);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->inteiro($this->corpoJson()['entradas'] ?? null));

        // Segunda passada — o print compartilhado no grupo da família.
        $this->utilizar(self::CODIGO, self::EVENTO_DA_PORTA, $token);

        self::assertSame(
            Response::HTTP_CONFLICT,
            $this->cliente->getResponse()->getStatusCode(),
        );

        $problema = $this->corpoJson();

        self::assertSame(
            'https://comprarbem.store/erros/ingresso-ja-utilizado',
            $problema['type'] ?? null,
            'A tela escolhe a mensagem pelo `type`, nunca pela frase.',
        );

        /*
          O horário vem em CAMPO PRÓPRIO, e não só dentro do texto.

          Ele está no `detail` também ("já utilizado às 19h42"), e seria
          tentador o front extrair de lá com regex. Isso quebra na primeira vez
          que alguém melhorar a frase. A RFC 7807 prevê membros de extensão
          exatamente para dados que a tela consome.
        */
        self::assertArrayHasKey(
            'utilizadoEm',
            $problema,
            'Sem este campo, a porta não consegue dizer QUANDO a pessoa entrou '
            .'sem interpretar texto.',
        );
        self::assertNotSame('', $this->texto($problema['utilizadoEm'] ?? null));
    }

    #[Test]
    #[TestDox('PLAN 7.3: ingresso válido de OUTRO evento é recusado com o motivo certo')]
    public function ingressoDeOutroEventoERecusado(): void
    {
        $token = $this->entrarComo('portaria@lugar.test');

        // O código existe, está pago e não foi usado. Só não é desta porta.
        $this->utilizar(self::CODIGO_VIZINHO, self::EVENTO_DA_PORTA, $token);

        self::assertSame(
            Response::HTTP_CONFLICT,
            $this->cliente->getResponse()->getStatusCode(),
        );
        self::assertSame(
            'https://comprarbem.store/erros/ingresso-de-outro-evento',
            $this->corpoJson()['type'] ?? null,
            '"Código inválido" mandaria a pessoa procurar erro de digitação; '
            .'o motivo certo a manda para a porta certa.',
        );

        // E o ingresso do vizinho continua intacto para a porta dele.
        self::assertSame(
            'EMITIDO',
            $this->umTexto('SELECT status FROM ingresso WHERE codigo = :c', ['c' => self::CODIGO_VIZINHO]),
        );
    }

    // ── a escala (ADR-004) ───────────────────────────────────────────────

    #[Test]
    #[TestDox('ADR-004: porteiro com ROLE_PORTARIA em evento onde NÃO está escalado leva 403')]
    public function porteiroNaoEscaladoNaoValida(): void
    {
        // Tem o papel. Tem token válido. Não está escalado neste evento.
        $this->utilizar(self::CODIGO_VIZINHO, self::OUTRO_EVENTO, $this->entrarComo('portaria@lugar.test'));

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->cliente->getResponse()->getStatusCode(),
            'Papel não basta: a escala em evento_operador é que diz em qual '
            .'porta. Se este teste virar 200, o PortariaVoter saiu do caminho.',
        );

        self::assertSame(
            'EMITIDO',
            $this->umTexto('SELECT status FROM ingresso WHERE codigo = :c', ['c' => self::CODIGO_VIZINHO]),
        );
    }

    #[Test]
    #[TestDox('o organizador dono valida a própria porta sem se escalar')]
    public function organizadorDonoValida(): void
    {
        $this->utilizar(self::CODIGO, self::EVENTO_DA_PORTA, $this->entrarComo('rafael@lugar.test'));

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[TestDox('comprador comum não valida nada')]
    public function compradorNaoValida(): void
    {
        $this->utilizar(self::CODIGO, self::EVENTO_DA_PORTA, $this->entrarComo('ana@lugar.test'));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->cliente->getResponse()->getStatusCode());
    }

    #[Test]
    #[TestDox('sem token a porta não abre')]
    public function semTokenNaoValida(): void
    {
        $this->cliente->request(
            'POST',
            sprintf('/api/ingressos/%s/utilizar', self::CODIGO),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['eventoId' => self::EVENTO_DA_PORTA], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->cliente->getResponse()->getStatusCode());
    }

    // ── digitação e consulta ─────────────────────────────────────────────

    #[Test]
    #[TestDox('código inexistente e código malformado dão a MESMA resposta')]
    public function codigoDesconhecidoEMalformadoSaoIguais(): void
    {
        $token = $this->entrarComo('portaria@lugar.test');

        // Na fila, os dois casos são "confira a digitação". Distinguir só
        // ajudaria quem está testando códigos até acertar um.
        foreach (['LGR-ZZZZ-ZZZZ', 'ABC'] as $codigo) {
            $this->utilizar($codigo, self::EVENTO_DA_PORTA, $token);

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->cliente->getResponse()->getStatusCode(),
                sprintf('O código "%s" deveria dar 404.', $codigo),
            );
            self::assertSame(
                'https://comprarbem.store/erros/codigo-desconhecido',
                $this->corpoJson()['type'] ?? null,
            );
        }
    }

    #[Test]
    #[TestDox('o código é aceito em minúsculas — ninguém digita com Caps Lock na fila')]
    public function codigoEmMinusculasFunciona(): void
    {
        $this->utilizar(mb_strtolower(self::CODIGO), self::EVENTO_DA_PORTA, $this->entrarComo('portaria@lugar.test'));

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[TestDox('consultar NÃO consome a entrada')]
    public function consultarNaoConsome(): void
    {
        $token = $this->entrarComo('portaria@lugar.test');

        $this->cliente->request(
            'GET',
            sprintf('/api/ingressos/%s?eventoId=%s', self::CODIGO, self::EVENTO_DA_PORTA),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('EMITIDO', $this->texto($this->corpoJson()['status'] ?? null));

        // A conferência prévia não pode gastar o ingresso de quem ainda está
        // do lado de fora.
        self::assertSame(
            'EMITIDO',
            $this->umTexto('SELECT status FROM ingresso WHERE codigo = :c', ['c' => self::CODIGO]),
        );
    }

    #[Test]
    #[TestDox('consultar ingresso de outro evento devolve 404, e não os dados dele')]
    public function consultarNaoVazaOutroEvento(): void
    {
        $this->cliente->request(
            'GET',
            sprintf('/api/ingressos/%s?eventoId=%s', self::CODIGO_VIZINHO, self::EVENTO_DA_PORTA),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->entrarComo('portaria@lugar.test')],
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->cliente->getResponse()->getStatusCode());

        // Nome e e-mail do comprador do evento vizinho não podem sair daqui.
        self::assertStringNotContainsString(
            'vizinho@lugar.test',
            (string) $this->cliente->getResponse()->getContent(),
        );
    }

    // ── mecânica ─────────────────────────────────────────────────────────

    private function utilizar(string $codigo, string $eventoId, string $token): void
    {
        $this->cliente->request(
            'POST',
            sprintf('/api/ingressos/%s/utilizar', rawurlencode($codigo)),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            content: json_encode(['eventoId' => $eventoId], \JSON_THROW_ON_ERROR),
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

    private function inteiro(mixed $valor): int
    {
        if (!\is_int($valor)) {
            self::fail('Esperava um número inteiro no corpo da resposta.');
        }

        return $valor;
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
        $hash = $this->servico(HashDeSenha::class);
        $usuarios = $this->servico(RepositorioDeUsuarios::class);

        $rafael = $this->usuario('rafael', 'rafael@lugar.test', [Papel::COMPRADOR, Papel::ORGANIZADOR], $hash);
        $porteiro = $this->usuario('porteiro', 'portaria@lugar.test', [Papel::COMPRADOR, Papel::PORTARIA], $hash);
        $ana = $this->usuario('ana', 'ana@lugar.test', [Papel::COMPRADOR], $hash);

        foreach ([$rafael, $porteiro, $ana] as $usuario) {
            $usuarios->salvar($usuario);
        }

        $this->criarEventoComIngresso(self::EVENTO_DA_PORTA, $rafael, self::CODIGO, 'ana@lugar.test');
        $this->criarEventoComIngresso(self::OUTRO_EVENTO, $rafael, self::CODIGO_VIZINHO, 'vizinho@lugar.test');

        // A portaria é escalada em UM evento só — é assim que se demonstra o
        // PortariaVoter recusando ingresso de outra porta.
        $this->servico(EscalaDePortaria::class)->escalar($porteiro->id, new EventoId(self::EVENTO_DA_PORTA));

        $this->servico(EntityManagerInterface::class)->clear();
    }

    private function criarEventoComIngresso(
        string $eventoId,
        Usuario $dono,
        string $codigo,
        string $compradorEmail,
    ): void {
        $evento = Evento::criar(
            new EventoId($eventoId),
            $dono->id,
            sprintf('Evento %s', $eventoId),
            'Teatro B32',
            'São Paulo',
            new \DateTimeImmutable('+30 days'),
        );
        $evento->publicar();
        $this->servico(RepositorioDeEventos::class)->salvar($evento);

        $this->servico(RepositorioDeLotes::class)->salvar(new Lote(
            new LoteId($eventoId.'-lote'),
            $evento->id,
            'Lote único',
            Dinheiro::emCentavos(220_00),
            100,
            1,
            Periodo::de(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+29 days')),
        ));

        $reserva = Reserva::criar(
            new ReservaId($eventoId.'-reserva'),
            new LoteId($eventoId.'-lote'),
            $compradorEmail,
            1,
            Dinheiro::emCentavos(220_00),
            new \DateTimeImmutable(),
        );
        $reserva->confirmar(new \DateTimeImmutable('+1 minute'));
        $this->servico(RepositorioDeReservas::class)->salvar($reserva);

        $this->servico(RepositorioDeIngressos::class)->salvar(Ingresso::emitir(
            new IngressoId($eventoId.'-ingresso'),
            $reserva->id,
            new CodigoIngresso($codigo),
            new \DateTimeImmutable(),
        ));
    }

    /**
     * @param list<Papel> $papeis
     */
    private function usuario(string $id, string $email, array $papeis, HashDeSenha $hash): Usuario
    {
        return Usuario::cadastrar(
            new UsuarioId($id),
            $email,
            self::SENHA,
            'Fulano de Tal',
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

        foreach (['pagamento', 'ingresso', 'reserva', 'lote', 'evento_operador', 'token_de_renovacao', 'evento', 'usuario'] as $tabela) {
            $conexao->executeStatement(sprintf('DELETE FROM %s', $tabela));
        }
    }
}
