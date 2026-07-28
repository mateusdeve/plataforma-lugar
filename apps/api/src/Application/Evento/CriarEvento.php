<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Application\Comum\Transacao;
use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Usuario\UsuarioId;

/**
 * Fase 6.1 — o evento nasce em RASCUNHO, com seus lotes, numa transação só.
 *
 * Evento e Lote são agregados distintos (ADR-001), mas nascem juntos: um
 * evento sem lote não tem o que vender, e um lote órfão não tem onde aparecer.
 * Se a gravação do segundo lote falhar, o evento inteiro desfaz — não existe
 * "criou pela metade" para o organizador reencontrar depois.
 *
 * A janela de venda dos lotes abre AGORA e não fecha: o formulário não pede
 * datas (design/organizador/02), e o rascunho segura a vitrine até o
 * organizador publicar. Quem controla quando a venda começa é a publicação.
 */
final readonly class CriarEvento
{
    public function __construct(
        private Transacao $transacao,
        private RepositorioDeEventos $eventos,
        private RepositorioDeLotes $lotes,
        private GeradorDeIdentidade $gerador,
        private Relogio $relogio,
    ) {
    }

    public function __invoke(CriarEventoComando $comando): Evento
    {
        if ([] === $comando->lotes) {
            throw new \InvalidArgumentException('O evento precisa de ao menos um lote.');
        }

        return $this->transacao->executar(function () use ($comando): Evento {
            $agora = $this->relogio->agora();

            $evento = Evento::criar(
                $this->gerador->novoEventoId(),
                new UsuarioId($comando->organizadorId),
                $comando->titulo,
                $comando->local,
                $comando->cidade,
                $comando->iniciaEm,
                $comando->descricao,
                $comando->prazoReservaMinutos,
            );

            $this->eventos->salvar($evento);

            foreach ($comando->lotes as $lote) {
                $this->lotes->salvar(new Lote(
                    $this->gerador->novoLoteId(),
                    $evento->id,
                    $lote['nome'],
                    Dinheiro::emCentavos($lote['precoCentavos']),
                    $lote['quantidade'],
                    0,
                    Periodo::aPartirDe($agora),
                ));
            }

            return $evento;
        });
    }
}
