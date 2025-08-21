<?php

declare(strict_types=1);

namespace App\Game\Movement\DoctrineDBAL;

use App\Game\Field\FieldPlace;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class PlayerPositionMapType extends Type
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
     * @return ?array<string, FieldPlace>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        // If it's already an array, it means Doctrine has already decoded the JSON
        if (\is_array($value)) {
            $decodedValue = $value;
        } else {
            // If it's a string, decode it first
            $decodedValue = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);
        }

        if ($decodedValue === null) {
            return null;
        }

        if (!\is_array($decodedValue)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, string|%s>', FieldPlace::class)]);
        }

        $result = [];
        foreach ($decodedValue as $playerId => $position) {
            if (!\is_string($playerId)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, string|%s>', FieldPlace::class)]);
            }

            if ($position instanceof FieldPlace) {
                $result[$playerId] = $position;
            } elseif (\is_string($position)) {
                try {
                    $result[$playerId] = FieldPlace::fromString($position);
                } catch (\InvalidArgumentException $exception) {
                    throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                }
            } elseif (\is_array($position)) {
                // Handle array format (e.g., from FieldPlace::fromAnything)
                try {
                    /** @var array{positionX: int, positionY: int} $position */
                    $result[$playerId] = FieldPlace::fromAnything($position);
                } catch (\InvalidArgumentException $exception) {
                    throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                }
            } else {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, string|%s>', FieldPlace::class)]);
            }
        }

        return $result;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', FieldPlace::class)]);
        }

        $converted = [];
        foreach ($value as $playerId => $position) {
            if (!\is_string($playerId)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', FieldPlace::class)]);
            }

            if ($position instanceof FieldPlace) {
                $converted[$playerId] = $position->toString();
            } else {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', FieldPlace::class)]);
            }
        }

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
