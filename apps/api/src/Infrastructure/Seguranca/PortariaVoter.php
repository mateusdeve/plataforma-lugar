<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\Evento;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autoriza validar ingresso na porta.
 *
 * Duas formas de conseguir: ser o organizador DONO do evento, ou estar
 * ESCALADO nele (tabela `evento_operador`).
 *
 * Sem esta segunda checagem, `ROLE_PORTARIA` autorizaria validar ingresso de
 * qualquer evento — inclusive de outro organizador, em outra cidade, no mesmo
 * fim de semana. O papel diz "esta pessoa trabalha em portaria"; a escala diz
 * "nesta porta, hoje".
 *
 * @extends Voter<string, Evento>
 */
/*
 * Não é `readonly`: o Voter do Symfony não é uma classe readonly, e PHP proíbe
 * uma classe readonly estender uma que não é. A propriedade promovida abaixo
 * segue readonly individualmente, que é o que importa.
 */
final class PortariaVoter extends Voter
{
    public const string VALIDAR = 'PORTARIA_VALIDAR';

    public function __construct(private readonly EscalaDePortaria $escala)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VALIDAR === $attribute && $subject instanceof Evento;
    }

    /**
     * @param Evento $subject
     */
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool
    {
        $autenticado = $token->getUser();

        if (!$autenticado instanceof UsuarioAutenticado) {
            return false;
        }

        $usuario = $autenticado->usuario;

        // O organizador dono valida a própria porta sem precisar se escalar.
        if ($usuario->ehOrganizador() && $subject->pertenceA($usuario->id)) {
            return true;
        }

        return $usuario->ehPortaria()
            && $this->escala->estaEscalado($usuario->id, $subject->id);
    }
}
