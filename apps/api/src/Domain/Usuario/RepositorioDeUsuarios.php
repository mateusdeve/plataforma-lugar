<?php

declare(strict_types=1);

namespace Lugar\Domain\Usuario;

interface RepositorioDeUsuarios
{
    public function buscar(UsuarioId $id): ?Usuario;

    public function buscarPorEmail(string $email): ?Usuario;

    public function salvar(Usuario $usuario): void;
}
