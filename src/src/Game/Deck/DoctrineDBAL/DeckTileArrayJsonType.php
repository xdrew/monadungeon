<?php

declare(strict_types=1);

namespace App\Game\Deck\DoctrineDBAL;

use App\Game\Deck\DeckTile;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class DeckTileArrayJsonType extends Type
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
     * @return ?array<DeckTile>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string|%s>', DeckTile::class)]);
        }

        return array_map(
            function (mixed $item) use ($value): DeckTile {
                if ($item instanceof DeckTile) {
                    return $item;
                }

                if (!\is_string($item)) {
                    throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string|%s>', DeckTile::class)]);
                }

                try {
                    return DeckTile::fromString($item);
                } catch (\InvalidArgumentException $exception) {
                    throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                }
            },
            $value,
        );
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', DeckTile::class)]);
        }

        $converted = array_map(
            function (mixed $item) use ($value): string {
                if ($item instanceof DeckTile) {
                    return $item->toString();
                }

                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', DeckTile::class)]);
            },
            $value,
        );
        /* @psalm-check-type-exact $converted = \array<non-empty-string> */

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
