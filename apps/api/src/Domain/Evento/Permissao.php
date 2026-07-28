<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

/**
 * O vocabulário do que se pode fazer com um evento.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUE ISTO MORA EM Domain/ E NÃO NO VOTER
 *
 * Os Voters vivem em `Infrastructure/`, porque estendem uma classe do Symfony.
 * Os controllers vivem em `UI/`, e o deptrac proíbe UI de depender de
 * Infrastructure — com razão: o controller não deve saber *como* a permissão é
 * decidida, só *qual* permissão está pedindo.
 *
 * Sem um lugar neutro, sobraria a string literal `'EVENTO_VER_PAINEL'` repetida
 * no controller e no Voter, livre para divergir por um typo — e um typo aqui
 * não quebra nada de forma visível: o Voter simplesmente não é chamado, e o
 * `access_control` de papel deixa passar. A falha seria silenciosa e permissiva,
 * que é a pior combinação possível numa checagem de autorização.
 *
 * Estas constantes são texto puro, sem framework. Nomear o que se pode fazer
 * com um evento é decisão de domínio; decidir quem pode é do Voter.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class Permissao
{
    public const string VER = 'EVENTO_VER';
    public const string EDITAR = 'EVENTO_EDITAR';
    public const string PUBLICAR = 'EVENTO_PUBLICAR';
    public const string VER_PAINEL = 'EVENTO_VER_PAINEL';
    public const string ESCALAR_PORTARIA = 'EVENTO_ESCALAR_PORTARIA';

    /**
     * Decidida pelo `PortariaVoter`, e só por ele: é a única permissão com
     * DOIS caminhos de concessão — ser o organizador dono, ou estar escalado
     * na porta. Deixá-la também no `EventoVoter` criaria dois lugares votando
     * a mesma coisa, e a estratégia afirmativa do Symfony faria o "sim" de um
     * deles bastar. Uma regra com duas fontes é uma regra que ninguém lê
     * inteira antes de mudar.
     */
    public const string VALIDAR_INGRESSO = 'EVENTO_VALIDAR_INGRESSO';

    private function __construct()
    {
    }
}
