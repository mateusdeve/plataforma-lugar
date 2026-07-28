<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Application\Pagamento\Excecao\AssinaturaInvalida;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * A ASSINATURA É VERIFICADA ANTES DE QUALQUER PROCESSAMENTO.
 *
 * O PLAN.md (5.3) exige isso, e o motivo é direto: este endpoint é público, não
 * tem sessão, e recebe ordens para confirmar pagamento. Sem assinatura válida,
 * qualquer pessoa com a URL emite ingressos de graça mandando um JSON.
 *
 * POR QUE VERIFICAR E INTERPRETAR SÃO O MESMO MÉTODO
 *
 * A versão intuitiva seria `verificar()` e depois `interpretar()`. Ela funciona
 * enquanto todo mundo lembrar da ordem — e some no dia em que alguém precisar
 * "só dar uma olhada no tipo do evento antes" e chamar `interpretar()` primeiro.
 * Aí a validação continua no arquivo, o teste continua passando, e o endpoint
 * está aberto.
 *
 * Com um método só, não existe caminho que devolva dados sem ter conferido a
 * assinatura. A ordem deixa de ser disciplina e passa a ser tipo: quem quer o
 * `AvisoDePagamento` tem de passar pela verificação para obtê-lo.
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface WebhookDePagamento
{
    /**
     * @param string                $corpoBruto o corpo EXATO como chegou, byte
     *                                          a byte — reserializar o JSON
     *                                          muda espaços e quebra o HMAC
     * @param array<string, string> $cabecalhos
     *
     * @throws AssinaturaInvalida
     */
    public function verificarEInterpretar(string $corpoBruto, array $cabecalhos): AvisoDePagamento;
}
