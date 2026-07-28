<?php

declare(strict_types=1);

namespace Lugar\Application\Evento;

use Lugar\Application\Comum\Transacao;
use Lugar\Application\Evento\Excecao\OperadorDesconhecido;
use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;

/**
 * Fase 6.4 — o organizador escala quem valida ingresso na porta do SEU evento.
 *
 * O papel vem junto com a escala, de propósito: papéis são acumuláveis
 * (ADR-004) e não existe balcão de administração para conceder ROLE_PORTARIA
 * — quem decide que a pessoa trabalha na porta é o organizador que a escala.
 * Exigir o papel de antemão criaria um estado impossível de alcançar: o
 * cadastro só distribui comprador e organizador (ver AuthController).
 *
 * Conceder o papel sem escalar não abriria porta nenhuma: o PortariaVoter
 * exige as duas coisas, e é a escala que diz "nesta porta, hoje".
 */
final readonly class EscalarOperador
{
    public function __construct(
        private Transacao $transacao,
        private RepositorioDeUsuarios $usuarios,
        private EscalaDePortaria $escala,
    ) {
    }

    /**
     * O controller já garantiu posse do evento (EventoVoter).
     *
     * @throws OperadorDesconhecido 404 type=operador-desconhecido
     */
    public function __invoke(EventoId $eventoId, string $email): Usuario
    {
        return $this->transacao->executar(function () use ($eventoId, $email): Usuario {
            $usuario = $this->usuarios->buscarPorEmail($email)
                ?? throw new OperadorDesconhecido(
                    sprintf('Não existe conta com o e-mail %s. Peça para a pessoa se cadastrar antes.', $email),
                );

            $usuario->conceder(Papel::PORTARIA);
            $this->usuarios->salvar($usuario);
            $this->escala->escalar($usuario->id, $eventoId);

            return $usuario;
        });
    }
}
