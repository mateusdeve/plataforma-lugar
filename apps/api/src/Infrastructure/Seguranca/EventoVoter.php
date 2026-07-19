<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Seguranca;

use Lugar\Domain\Evento\Evento;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * PAPEL NÃO BASTA. É PRECISO VÍNCULO.
 *
 * `ROLE_ORGANIZADOR` diz que a pessoa pode organizar eventos. Não diz QUAIS.
 *
 * Um sistema que confere apenas o papel deixa qualquer organizador ler o
 * painel, a lista de compradores e o faturamento de qualquer outro. É a falha
 * de autorização mais comum que existe, e passa despercebida porque a tela
 * nunca oferece o link — mas a API não sabe o que a tela oferece.
 *
 * POR QUE VOTER, E NÃO UM `if` NO CONTROLLER
 *
 * O `if` funciona no controller onde foi escrito. Ele não funciona no próximo
 * endpoint, que alguém vai escrever daqui a três semanas sem lembrar da regra.
 * O Voter é um lugar só, testável isoladamente, invocado por
 * `#[IsGranted]` — e a ausência dele é visível na revisão.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @extends Voter<string, Evento>
 */
final class EventoVoter extends Voter
{
    public const string VER = 'EVENTO_VER';
    public const string EDITAR = 'EVENTO_EDITAR';
    public const string PUBLICAR = 'EVENTO_PUBLICAR';
    public const string VER_PAINEL = 'EVENTO_VER_PAINEL';
    public const string ESCALAR_PORTARIA = 'EVENTO_ESCALAR_PORTARIA';
    public const string VALIDAR_INGRESSO = 'EVENTO_VALIDAR_INGRESSO';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Evento && \in_array($attribute, [
            self::VER,
            self::EDITAR,
            self::PUBLICAR,
            self::VER_PAINEL,
            self::ESCALAR_PORTARIA,
            self::VALIDAR_INGRESSO,
        ], true);
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

        // Ver um evento PUBLICADO é público — é a vitrine.
        if (self::VER === $attribute && $subject->estaPublicado()) {
            return true;
        }

        // Todo o resto exige as DUAS coisas: o papel e o vínculo.
        // Trocar este `&&` por `||` é um bug de segurança silencioso, e é
        // exatamente o que o teste de autorização negativa existe para pegar.
        return $usuario->ehOrganizador() && $subject->pertenceA($usuario->id);
    }
}
