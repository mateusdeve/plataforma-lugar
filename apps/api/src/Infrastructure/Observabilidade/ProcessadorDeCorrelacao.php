<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Observabilidade;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Carimba `correlation_id` em TODO log emitido durante a unidade de trabalho.
 *
 * É por isso que nenhum caso de uso recebe o id por parâmetro: quem loga não
 * precisa saber que a correlação existe. O processador roda em cada registro,
 * de qualquer canal, e o campo aparece no JSON de produção pronto para o
 * `docker logs | grep`.
 */
#[AsMonologProcessor]
final readonly class ProcessadorDeCorrelacao implements ProcessorInterface
{
    public function __construct(private ContextoDeCorrelacao $contexto)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->contexto->atual();

        if (null !== $id) {
            $record->extra['correlation_id'] = $id;
        }

        return $record;
    }
}
