<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Pagamento;

use Lugar\Application\Pagamento\AvisoDePagamento;
use Lugar\Application\Pagamento\Excecao\AssinaturaInvalida;
use Lugar\Application\Pagamento\WebhookDePagamento;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\Relogio;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VERIFICAÇÃO HMAC DO WEBHOOK — O ÚNICO PORTÃO DESTE ENDPOINT.
 *
 * `/api/webhooks/pagamento` é público: não tem sessão, não tem token, e manda
 * emitir ingresso. O que separa o provedor de qualquer pessoa na internet é
 * este arquivo.
 *
 * O FORMATO É O DO STRIPE, DE PROPÓSITO
 *
 *   Assinatura-Pagamento: t=1729350000,v1=<hex do HMAC-SHA256>
 *
 * onde o HMAC é calculado sobre a string `"{t}.{corpo bruto}"` com o segredo
 * compartilhado. Adotar o esquema de um provedor real desde o adaptador de
 * demonstração significa que ligar o Stripe de verdade troca a origem dos
 * dados, não o formato — e o teste que roda hoje continua provando a mesma
 * coisa amanhã.
 *
 * AS TRÊS DEFESAS, E O QUE CADA UMA IMPEDE
 *
 * 1. HMAC sobre o corpo BRUTO. Não sobre o JSON reserializado: `json_decode`
 *    seguido de `json_encode` normaliza espaços, ordem de chaves e escapes.
 *    O byte muda, o hash muda, e a verificação passaria a falhar com payload
 *    legítimo — ou, pior, alguém "consertaria" comparando o payload decodificado
 *    e a assinatura deixaria de proteger o que de fato chegou.
 *
 * 2. `hash_equals`. Comparar com `===` vaza o segredo por tempo: a comparação
 *    de strings do PHP para no primeiro byte diferente, então adivinhar byte a
 *    byte medindo o tempo de resposta é viável. `hash_equals` gasta o mesmo
 *    tempo sempre.
 *
 * 3. Janela de tempo. Sem ela, uma requisição legítima capturada uma vez pode
 *    ser reenviada para sempre — a assinatura continua válida, porque nada nela
 *    envelhece. Cinco minutos é a tolerância do próprio Stripe.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final readonly class AssinaturaHmac implements WebhookDePagamento
{
    public const string CABECALHO = 'Assinatura-Pagamento';

    /** Tolerância de relógio entre nós e o provedor. */
    private const int JANELA_EM_SEGUNDOS = 300;

    public function __construct(
        private string $segredoDoWebhook,
        private Relogio $relogio,
    ) {
    }

    public function verificarEInterpretar(string $corpoBruto, array $cabecalhos): AvisoDePagamento
    {
        if ('' === $this->segredoDoWebhook) {
            // Sem segredo configurado, qualquer HMAC "confere" com string
            // vazia. Falhar fechado é a única opção: um webhook aberto emite
            // ingressos de graça.
            throw new AssinaturaInvalida('PAGAMENTO_WEBHOOK_SEGREDO não está definido');
        }

        [$instante, $assinatura] = $this->partesDe($cabecalhos);

        $agora = $this->relogio->agora()->getTimestamp();

        if (abs($agora - $instante) > self::JANELA_EM_SEGUNDOS) {
            throw new AssinaturaInvalida('instante fora da janela de tolerância');
        }

        $esperada = hash_hmac('sha256', sprintf('%d.%s', $instante, $corpoBruto), $this->segredoDoWebhook);

        if (!hash_equals($esperada, $assinatura)) {
            throw new AssinaturaInvalida('HMAC não confere');
        }

        // Só aqui, com a assinatura conferida, o corpo é olhado pela primeira
        // vez. Até esta linha ele foi tratado como uma sequência de bytes.
        return $this->interpretar($corpoBruto);
    }

    /**
     * Monta o cabeçalho. Usado pelo adaptador de demonstração e pelos testes —
     * assinar e verificar com o mesmo código é o que garante que os dois lados
     * concordam sobre o formato.
     */
    public static function assinar(string $corpoBruto, string $segredo, int $instante): string
    {
        return sprintf(
            't=%d,v1=%s',
            $instante,
            hash_hmac('sha256', sprintf('%d.%s', $instante, $corpoBruto), $segredo),
        );
    }

    /**
     * @param array<string, string> $cabecalhos
     *
     * @return array{int, string}
     */
    private function partesDe(array $cabecalhos): array
    {
        $bruto = null;

        // Nome de cabeçalho é case-insensitive (RFC 9110), e cada cliente HTTP
        // capitaliza do seu jeito.
        foreach ($cabecalhos as $nome => $valor) {
            if (0 === strcasecmp($nome, self::CABECALHO)) {
                $bruto = $valor;
                break;
            }
        }

        if (null === $bruto) {
            throw new AssinaturaInvalida('cabeçalho de assinatura ausente');
        }

        $instante = null;
        $assinatura = null;

        foreach (explode(',', $bruto) as $parte) {
            $par = explode('=', trim($parte), 2);

            if (2 !== \count($par)) {
                continue;
            }

            if ('t' === $par[0] && ctype_digit($par[1])) {
                $instante = (int) $par[1];
            }

            if ('v1' === $par[0]) {
                $assinatura = $par[1];
            }
        }

        if (null === $instante || null === $assinatura) {
            throw new AssinaturaInvalida('cabeçalho de assinatura malformado');
        }

        return [$instante, $assinatura];
    }

    private function interpretar(string $corpoBruto): AvisoDePagamento
    {
        $dados = json_decode($corpoBruto, true);

        if (!\is_array($dados)) {
            throw new AssinaturaInvalida('corpo não é JSON de objeto');
        }

        return new AvisoDePagamento(
            provedorId: $this->texto($dados, 'id'),
            reservaId: $this->texto($dados, 'reserva_id'),
            valor: Dinheiro::emCentavos($this->inteiro($dados, 'valor_centavos')),
            aprovado: 'aprovado' === ($dados['status'] ?? null),
            payloadBruto: $corpoBruto,
        );
    }

    /**
     * @param array<array-key, mixed> $dados
     */
    private function texto(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? null;

        if (!\is_string($valor) || '' === trim($valor)) {
            throw new AssinaturaInvalida(sprintf('campo "%s" ausente ou inválido', $campo));
        }

        return $valor;
    }

    /**
     * @param array<array-key, mixed> $dados
     */
    private function inteiro(array $dados, string $campo): int
    {
        $valor = $dados[$campo] ?? null;

        if (!\is_int($valor) || $valor < 0) {
            throw new AssinaturaInvalida(sprintf('campo "%s" ausente ou inválido', $campo));
        }

        return $valor;
    }
}
