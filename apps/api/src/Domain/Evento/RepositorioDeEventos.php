<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

interface RepositorioDeEventos
{
    public function buscar(EventoId $id): ?Evento;

    public function salvar(Evento $evento): void;

    /**
     * Remove o evento e tudo que pende dele: lotes, reservas nunca
     * confirmadas e a escala da portaria.
     *
     * Quem chama é `ExcluirEvento`, DEPOIS de a RN-12 garantir que não há
     * venda confirmada — este método não repete a checagem, executa a decisão.
     */
    public function excluir(Evento $evento): void;
}
