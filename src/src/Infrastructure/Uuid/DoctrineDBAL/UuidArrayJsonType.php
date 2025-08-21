<?php

declare(strict_types=1);

namespace App\Infrastructure\Uuid\DoctrineDBAL;

use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class UuidArrayJsonType extends Type
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
     * @return ?array<Uuid>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<uuid-string|%s>', Uuid::class)]);
        }

        return array_map(
            function (mixed $item) use ($value): Uuid {
                if ($item instanceof Uuid) {
                    return $item;
                }

                if (!\is_string($item)) {
                    throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<uuid-string|%s>', Uuid::class)]);
                }

                try {
                    return Uuid::fromString($item);
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
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', Uuid::class)]);
        }

        $converted = array_map(
            function (mixed $item) use ($value): string {
                if ($item instanceof Uuid) {
                    return $item->toString();
                }

                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<%s>', Uuid::class)]);
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
