<?php

declare(strict_types=1);

namespace Lugar\UI\Console;

use Lugar\Domain\Comum\Dinheiro;
use Lugar\Domain\Comum\GeradorDeIdentidade;
use Lugar\Domain\Comum\Periodo;
use Lugar\Domain\Comum\Relogio;
use Lugar\Domain\Evento\EscalaDePortaria;
use Lugar\Domain\Evento\Evento;
use Lugar\Domain\Evento\EventoId;
use Lugar\Domain\Evento\RepositorioDeEventos;
use Lugar\Domain\Lote\Lote;
use Lugar\Domain\Lote\LoteId;
use Lugar\Domain\Lote\RepositorioDeLotes;
use Lugar\Domain\Usuario\HashDeSenha;
use Lugar\Domain\Usuario\Papel;
use Lugar\Domain\Usuario\RepositorioDeUsuarios;
use Lugar\Domain\Usuario\Usuario;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Popula a base com os dados do pacote de design.
 *
 * Existe por um motivo prático: um sistema de venda de ingressos sem eventos é
 * uma tela vazia. Quem clonar o repositório ou abrir a demonstração precisa
 * ver algo em 90 segundos (PRD §14) — e precisa de contas prontas para entrar
 * em cada perfil.
 *
 * As senhas aqui são públicas de propósito: são credenciais de demonstração,
 * não de produção.
 */
#[AsCommand(name: 'lugar:popular', description: 'Popula a base com dados de demonstração')]
final class PopularBaseCommand extends Command
{
    public const string SENHA_DEMO = 'demonstracao123';

    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        private readonly RepositorioDeEventos $eventos,
        private readonly RepositorioDeLotes $lotes,
        private readonly EscalaDePortaria $escala,
        private readonly HashDeSenha $hash,
        private readonly GeradorDeIdentidade $gerador,
        private readonly Relogio $relogio,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $saida = new SymfonyStyle($input, $output);
        $agora = $this->relogio->agora();

        if (null !== $this->usuarios->buscarPorEmail('rafael@lugar.demo')) {
            $saida->warning('A base já foi populada. Nada a fazer.');

            return Command::SUCCESS;
        }

        $organizador = $this->criarUsuario(
            'rafael@lugar.demo',
            'Rafael Mendes',
            [Papel::COMPRADOR, Papel::ORGANIZADOR],
            $agora,
        );

        $porteiro = $this->criarUsuario(
            'portaria@lugar.demo',
            'Equipe de portaria',
            [Papel::COMPRADOR, Papel::PORTARIA],
            $agora,
        );

        $this->criarUsuario('ana@lugar.demo', 'Ana Souza', [Papel::COMPRADOR], $agora);

        // ── FrontZ Conf: três lotes, um esgotado, um em venda, um futuro ──
        $frontz = $this->criarEvento(
            $organizador,
            'frontz-conf-2026',
            'FrontZ Conf 2026',
            'Teatro B32',
            'São Paulo',
            '+55 days',
            'Um dia inteiro sobre o front-end que a gente escreve de verdade: performance, acessibilidade, arquitetura de componentes e as decisões difíceis entre uma sprint e outra. Palestras curtas, corredor longo — o melhor acontece no café.',
        );

        $this->criarLote($frontz, 'frontz-1', '1º lote', 180_00, 200, 200, '-60 days', '-1 day');
        $this->criarLote($frontz, 'frontz-2', '2º lote', 220_00, 310, 236, '-1 day', '+40 days');
        $this->criarLote($frontz, 'frontz-3', '3º lote', 260_00, 150, 0, '+20 days', null);

        // A portaria é escalada só neste evento — é o vínculo que o
        // PortariaVoter consulta (ADR-004).
        $this->escala->escalar($porteiro->id, $frontz->id);

        // ── Encontro PHP: últimos lugares, para exibir o estado "Últimos N" ──
        $php = $this->criarEvento(
            $organizador,
            'encontro-php-do-sul',
            'Encontro PHP do Sul',
            'Auditório do Caldeira',
            'Porto Alegre',
            '+76 days',
            'PHP moderno, sem nostalgia e sem defensiva: tipos, arquitetura, filas e o que mudou de verdade nos últimos anos. Um sábado à tarde com quem mantém sistema grande em produção.',
        );
        $this->criarLote($php, 'php-1', 'Lote único', 140_00, 120, 114, '-30 days', '+75 days');

        // ── Workshop DDD: esgotado ──
        $ddd = $this->criarEvento(
            $organizador,
            'workshop-ddd',
            'Workshop — DDD na prática',
            'Aldeia Cowork',
            'Curitiba',
            '+90 days',
            'Oito horas modelando um domínio real em grupo. Sem slide sobre o que é agregado — a gente descobre a fronteira errando e corrigindo, que é como se aprende.',
        );
        $this->criarLote($ddd, 'ddd-1', 'Lote único', 320_00, 40, 40, '-40 days', '+89 days');

        // ── NextConf: bastante estoque, bom para testar o fluxo feliz ──
        $next = $this->criarEvento(
            $organizador,
            'nextconf-brasil',
            'NextConf Brasil',
            'Cidade das Artes',
            'Rio de Janeiro',
            '+97 days',
            'Renderização, cache e as escolhas que sobram quando o framework já decidiu quase tudo. Conteúdo para quem já colocou Next em produção e apanhou.',
        );
        $this->criarLote($next, 'next-1', '1º lote', 190_00, 300, 42, '-20 days', '+96 days');

        $saida->success('Base populada.');
        $saida->table(
            ['perfil', 'e-mail', 'senha'],
            [
                ['Organizador', 'rafael@lugar.demo', self::SENHA_DEMO],
                ['Portaria', 'portaria@lugar.demo', self::SENHA_DEMO],
                ['Comprador', 'ana@lugar.demo', self::SENHA_DEMO],
            ],
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<Papel> $papeis
     */
    private function criarUsuario(string $email, string $nome, array $papeis, \DateTimeImmutable $agora): Usuario
    {
        $usuario = Usuario::cadastrar(
            $this->gerador->novoUsuarioId(),
            $email,
            self::SENHA_DEMO,
            $nome,
            $this->hash,
            $agora,
            $papeis,
        );

        $this->usuarios->salvar($usuario);

        return $usuario;
    }

    private function criarEvento(
        Usuario $organizador,
        string $id,
        string $titulo,
        string $local,
        string $cidade,
        string $quando,
        string $descricao,
    ): Evento {
        $evento = Evento::criar(
            new EventoId($id),
            $organizador->id,
            $titulo,
            $local,
            $cidade,
            $this->relogio->agora()->modify($quando)->setTime(9, 0),
            $descricao,
        );

        $evento->publicar();
        $this->eventos->salvar($evento);

        return $evento;
    }

    private function criarLote(
        Evento $evento,
        string $id,
        string $nome,
        int $precoCentavos,
        int $total,
        int $vendida,
        string $abre,
        ?string $fecha,
    ): void {
        $agora = $this->relogio->agora();

        $this->lotes->salvar(new Lote(
            new LoteId($id),
            $evento->id,
            $nome,
            Dinheiro::emCentavos($precoCentavos),
            $total,
            $vendida,
            Periodo::de(
                $agora->modify($abre),
                null === $fecha ? null : $agora->modify($fecha),
            ),
        ));
    }
}
