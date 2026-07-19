<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Lugar\Domain\Comum\Identidade;

/**
 * Converte as identidades tipadas do domínio (LoteId, ReservaId...) para uma
 * coluna de texto, e de volta.
 *
 * É o que permite ao domínio ter tipos distintos — impedindo passar um LoteId
 * onde se espera um ReservaId — sem que ele saiba que Doctrine existe.
 *
 * @template T of Identidade
 */
abstract class TipoDeIdentidade extends Type
{
    /** @return class-string<T> */
    abstract protected function classe(): string;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 64]);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Identidade) {
            throw new \InvalidArgumentException(
                sprintf('Esperava %s, recebeu %s.', $this->classe(), get_debug_type($value)),
            );
        }

        return $value->valor;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?Identidade
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException(
                sprintf('Identidade deve vir do banco como texto, veio %s.', get_debug_type($value)),
            );
        }

        $classe = $this->classe();

        return new $classe((string) $value);
    }
}
