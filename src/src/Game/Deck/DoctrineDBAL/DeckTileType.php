<?php

declare(strict_types=1);

namespace App\Game\Deck\DoctrineDBAL;

use App\Game\Deck\DeckTile;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class DeckTileType extends Type
{
    public function getName(): string
    {
        return self::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DeckTile
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DeckTile) {
            return $value;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', DeckTile::class]);
        }

        try {
            return DeckTile::fromString($value);
        } catch (\InvalidArgumentException $exception) {
            throw ConversionException::conversionFailed($value, $this->getName(), $exception);
        }
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DeckTile) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', DeckTile::class]);
        }

        try {
            return DeckTile::fromString($value)->toString();
        } catch (\InvalidArgumentException) {
            throw ConversionException::conversionFailed($value, $this->getName());
        }
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
