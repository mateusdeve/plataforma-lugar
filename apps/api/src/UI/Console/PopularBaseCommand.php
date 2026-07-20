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
use Lugar\Domain\Reserva\RepositorioDeReservas;
use Lugar\Domain\Reserva\Reserva;
use Lugar\Domain\Reserva\ReservaId;
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
        private readonly RepositorioDeReservas $reservas,
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

        // Compradores com conta, para a lista do painel ter nomes ao lado dos
        // convidados — é o contraste que o ADR-004 previu.
        $this->criarUsuario('bruno@lugar.demo', 'Bruno Lima', [Papel::COMPRADOR], $agora);
        $this->criarUsuario('carla@lugar.demo', 'Carla Mendes', [Papel::COMPRADOR], $agora);

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

        /*
          O lote em venda ganha reservas em aberto e reservas que venceram.

          Sem elas o painel mostraria "reservados agora: 0" e conversão de 100%,
          que é o número bonito e mentiroso: nenhuma reserva teria expirado
          porque nenhuma existiu. A taxa de expiração é a métrica que o PRD §6.5
          pede justamente por ser a incômoda.
        */
        $this->criarReservasEmAberto('frontz-2', Dinheiro::emCentavos(220_00));
        $this->criarReservasVencidas('frontz-2', Dinheiro::emCentavos(220_00), quantas: 48);

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
        $preco = Dinheiro::emCentavos($precoCentavos);

        $this->lotes->salvar(new Lote(
            new LoteId($id),
            $evento->id,
            $nome,
            $preco,
            $total,
            $vendida,
            Periodo::de(
                $agora->modify($abre),
                null === $fecha ? null : $agora->modify($fecha),
            ),
        ));

        $this->criarReservasConfirmadas($id, $preco, $vendida);
    }

    /*
      ═══════════════════════════════════════════════════════════════════════
      TODA VENDA PRECISA DE UMA RESERVA ATRÁS DELA.

      Antes, este comando gravava `quantidade_vendida` direto no lote e parava
      aí. O número aparecia na vitrine e tudo parecia certo — mas era um estado
      que a operação REAL nunca produz: ingresso vendido sem ninguém ter
      comprado.

      O painel do organizador expôs isso na cara: 436 vendidos e R$ 0,00 de
      receita, zero compradores, conversão zero. Não era bug do painel. A
      receita sai das reservas, porque é nelas que está o preço efetivamente
      pago (o do lote muda com o tempo), e não havia reserva nenhuma.

      Dado de demonstração que não poderia existir em produção é uma armadilha:
      esconde bugs que só aparecem com dado real, e faz tela correta parecer
      quebrada.
      ═══════════════════════════════════════════════════════════════════════
    */

    /** Grupos de compra, em ciclo — pedido real raramente é de 1 ingresso. */
    private const array TAMANHOS_DE_GRUPO = [2, 1, 4, 1, 3, 2, 1, 2];

    private function criarReservasConfirmadas(string $loteId, Dinheiro $preco, int $unidades): void
    {
        $restam = $unidades;
        $indice = 0;

        while ($restam > 0) {
            // RN-04 limita a 6 por reserva; o resto final entra numa só.
            $quantidade = min(self::TAMANHOS_DE_GRUPO[$indice % \count(self::TAMANHOS_DE_GRUPO)], $restam);

            // Espalha as compras pelo passado para "vendidos hoje" e a ordem da
            // lista de compradores fazerem sentido.
            $quando = $this->relogio->agora()->modify(sprintf('-%d hours', 1 + ($indice * 7) % 700));

            $this->reservaConfirmadaEm($loteId, $preco, $quantidade, $quando, $indice);

            $restam -= $quantidade;
            ++$indice;
        }
    }

    private function reservaConfirmadaEm(
        string $loteId,
        Dinheiro $preco,
        int $quantidade,
        \DateTimeImmutable $quando,
        int $indice,
    ): void {
        $reserva = Reserva::criar(
            new ReservaId(sprintf('%s-r%03d', $loteId, $indice)),
            new LoteId($loteId),
            $this->compradorDe($indice),
            $quantidade,
            Dinheiro::emCentavos($preco->centavos * $quantidade),
            $quando,
        );

        // Confirmar exige reserva ATIVA (RN-07). Em vez de burlar a regra
        // escrevendo o status na marra, a confirmação acontece um minuto depois
        // da criação — que é o que aconteceria de verdade.
        $reserva->confirmar($quando->modify('+1 minute'));

        $this->reservas->salvar($reserva);
    }

    /**
     * Reservas PENDENTES ainda no prazo: são as que seguram estoque agora, e
     * as únicas que a query do ADR-002 desconta do disponível.
     */
    private function criarReservasEmAberto(string $loteId, Dinheiro $preco): void
    {
        $agora = $this->relogio->agora();

        foreach ([['emAberto-1', 2, '-2 minutes'], ['emAberto-2', 1, '-6 minutes']] as [$sufixo, $quantidade, $quando]) {
            $this->reservas->salvar(Reserva::criar(
                new ReservaId($loteId.'-'.$sufixo),
                new LoteId($loteId),
                sprintf('emaberto-%s@exemplo.test', $sufixo),
                $quantidade,
                Dinheiro::emCentavos($preco->centavos * $quantidade),
                $agora->modify($quando),
            ));
        }
    }

    /**
     * Reservas que venceram sem pagamento — o denominador da conversão.
     *
     * A quantidade não é enfeite. Com três expiradas contra duzentas e tantas
     * vendas, o painel mostraria 99% de conversão: o número bonito que não
     * ensina nada e que esconderia um erro de cálculo na faixa que importa.
     * Cerca de 18% de expiração é o que se vê em venda de ingresso com prazo
     * curto, e é onde a métrica do PRD §6.5 tem alguma coisa a dizer.
     */
    private function criarReservasVencidas(string $loteId, Dinheiro $preco, int $quantas): void
    {
        $agora = $this->relogio->agora();

        for ($i = 0; $i < $quantas; ++$i) {
            $quantidade = self::TAMANHOS_DE_GRUPO[$i % \count(self::TAMANHOS_DE_GRUPO)];
            $criadaEm = $agora->modify(sprintf('-%d hours', 2 + ($i * 13) % 600));

            $reserva = Reserva::criar(
                new ReservaId(sprintf('%s-v%03d', $loteId, $i)),
                new LoteId($loteId),
                sprintf('desistiu%03d@exemplo.test', $i),
                $quantidade,
                Dinheiro::emCentavos($preco->centavos * $quantidade),
                $criadaEm,
            );

            $reserva->marcarComoExpirada($agora);

            $this->reservas->salvar($reserva);
        }
    }

    /**
     * Parte dos compradores tem conta (e nome na lista do painel); o resto é
     * checkout de convidado, que o ADR-004 manteve de propósito e que aparece
     * na tela só pelo e-mail.
     */
    private function compradorDe(int $indice): string
    {
        $comConta = ['ana@lugar.demo', 'bruno@lugar.demo', 'carla@lugar.demo'];

        return 0 === $indice % 4
            ? $comConta[intdiv($indice, 4) % \count($comConta)]
            : sprintf('convidado%03d@exemplo.test', $indice);
    }
}
