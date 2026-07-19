<?php

declare(strict_types=1);

namespace Lugar\Domain\Comum;

/**
 * Porta para "que horas são".
 *
 * Sem isto, RN-01 e RN-02 seriam intestáveis de forma determinística: um teste
 * que chama `new DateTimeImmutable()` depende do relógio da máquina e falha em
 * dias diferentes por motivos diferentes. Pior — testar "a reserva expira em 10
 * minutos" exigiria esperar 10 minutos.
 *
 * A interface é declarada aqui, no domínio, porque é o domínio que precisa
 * saber a hora. A implementação de produção vive em Infrastructure/; os testes
 * usam um relógio parado que avança quando mandam.
 */
interface Relogio
{
    public function agora(): \DateTimeImmutable;
}
