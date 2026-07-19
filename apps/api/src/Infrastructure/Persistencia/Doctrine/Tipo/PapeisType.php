<?php

declare(strict_types=1);

namespace Lugar\Infrastructure\Persistencia\Doctrine\Tipo;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Lugar\Domain\Usuario\Papel;

/**
 * Lista de papéis ↔ array JSON de strings.
 *
 * O tipo `json` do Doctrine grava o enum, mas hidrata de volta como STRING —
 * e aí `in_array($papel, $papeis, true)` no domínio compara enum com string e
 * nunca casa. O bug é silencioso: ninguém teria permissão para nada, e a causa
 * estaria a três camadas de distância.
 *
 * Este tipo faz a conversão explícita nas duas direções.
 */
final class PapeisType extends Type
{
    public const string NOME = 'papeis';

    public function getName(): string
    {
        return self::NOME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): string
    {
        if (!\is_array($value)) {
            return '[]';
        }

        $valores = [];

        foreach ($value as $papel) {
            // O domínio só produz Papel aqui, mas o tipo do Doctrine recebe
            // mixed — checar em vez de assumir.
            if ($papel instanceof Papel) {
                $valores[] = $papel->value;
            }
        }

        return json_encode($valores, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<Papel>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $decodificado = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decodificado)) {
            return [];
        }

        $papeis = [];

        foreach ($decodificado as $bruto) {
            // Um papel removido do enum continua no banco de quem já o tinha.
            // Ignorar é mais seguro que explodir: a pessoa perde aquele
            // acesso, e não o sistema inteiro.
            if (\is_string($bruto) && null !== ($papel = Papel::tryFrom($bruto))) {
                $papeis[] = $papel;
            }
        }

        return $papeis;
    }
}
