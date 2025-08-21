<?php

declare(strict_types=1);

namespace App\Game\Field\DoctrineDBAL;

use App\Game\Field\TileOrientation;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class TileOrientationMapType extends Type
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
     * @return array<string, TileOrientation>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType(
                $value,
                $this->getName(),
                ['null', sprintf('array<string, %s>', TileOrientation::class)],
            );
        }

        $map = [];
        foreach ($value as $key => $tileOrientationData) {
            if (!\is_string($key)) {
                throw ConversionException::conversionFailedInvalidType(
                    $value,
                    $this->getName(),
                    ['string keys expected'],
                );
            }

            if ($tileOrientationData instanceof TileOrientation) {
                $map[$key] = $tileOrientationData;

                continue;
            }

            if (!\is_string($tileOrientationData)) {
                throw ConversionException::conversionFailedInvalidType(
                    $value,
                    $this->getName(),
                    ['null', sprintf('array<string, string|%s>', TileOrientation::class)],
                );
            }

            try {
                $map[$key] = TileOrientation::fromString($tileOrientationData);
            } catch (\InvalidArgumentException $exception) {
                throw ConversionException::conversionFailed(
                    $value,
                    $this->getName(),
                    $exception,
                );
            }
        }

        return $map;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType(
                $value,
                $this->getName(),
                ['null', sprintf('array<string, %s>', TileOrientation::class)],
            );
        }

        $converted = [];
        foreach ($value as $key => $tileOrientation) {
            if (!\is_string($key)) {
                throw ConversionException::conversionFailedInvalidType(
                    $value,
                    $this->getName(),
                    ['string keys expected'],
                );
            }

            if ($tileOrientation instanceof TileOrientation) {
                $converted[$key] = $tileOrientation->toString();

                continue;
            }

            throw ConversionException::conversionFailedInvalidType(
                $value,
                $this->getName(),
                [sprintf('array<string, %s>', TileOrientation::class)],
            );
        }

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
