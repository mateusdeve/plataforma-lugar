<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Lugar\Domain\Ingresso\CodigoIngresso;

final class CodigoIngressoType extends Type
{
    public const string NOME = 'codigo_ingresso';

    public function getName(): string
    {
        return self::NOME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 13]);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof CodigoIngresso ? $value->valor : null;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CodigoIngresso
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf('Código deve vir do banco como texto, veio %s.', get_debug_type($value)),
            );
        }

        return new CodigoIngresso($value);
    }
}
