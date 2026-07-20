<?php

declare(strict_types=1);

namespace Lugar\Application\Pagamento;

use Lugar\Domain\Reserva\Reserva;

/**
 * Produz a requisição que o provedor mandaria — corpo e assinatura.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POR QUE ISTO É UMA PORTA, E NÃO O CONTROLLER MONTANDO O HMAC
 *
 * A rota de simulação precisa de um webhook ASSINADO, e assinar exige o
 * segredo. Se o controller fizesse isso, `UI` teria de importar o adaptador de
 * assinatura — o Deptrac barra (UI não conhece Infrastructure), e a regra está
 * certa: o formato do webhook é conhecimento do provedor.
 *
 * A alternativa preguiçosa seria a simulação chamar `ConfirmarPagamento`
 * direto, pulando a assinatura. Aí o caminho exercitado em desenvolvimento
 * deixaria de ser o caminho de produção — e o único trecho que protege o
 * endpoint mais exposto do sistema ficaria sem uso local.
 *
 * Com esta porta, simular é montar a mesma requisição e mandá-la pela mesma
 * verificação. O que muda é quem aperta o botão.
 * ═══════════════════════════════════════════════════════════════════════════
 */
interface SimuladorDePagamento
{
    public function webhookPara(Reserva $reserva, bool $aprovado): WebhookSimulado;
}
