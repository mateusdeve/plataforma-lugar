<?php

declare(strict_types=1);

namespace Lugar\Domain\Evento;

interface RepositorioDeEventos
{
    public function buscar(EventoId $id): ?Evento;

    public function salvar(Evento $evento): void;
}
