<?php

declare(strict_types=1);

namespace App\Game\Field\DoctrineDBAL;

use App\Game\Field\TileFeature;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class TileFeatureArrayType extends Type
{
    public function getName(): string
    {
        return self::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    /**
     * @return array<TileFeature>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null || $value === []) {
            return [];
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array']);
        }

        return array_map(
            function (mixed $item) use ($value): TileFeature {
                if ($item instanceof TileFeature) {
                    return $item;
                }

                if (!\is_string($item)) {
                    throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string|%s>', TileFeature::class)]);
                }

                try {
                    return TileFeature::from($item);
                } catch (\ValueError $exception) {
                    throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                }
            },
            $value,
        );
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null || $value === []) {
            return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue([], $platform);
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', TileFeature::class)]);
        }

        $converted = array_map(
            function (mixed $item) use ($value): string {
                if ($item instanceof TileFeature) {
                    return $item->value;
                }

                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', TileFeature::class)]);
            },
            $value,
        );

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
