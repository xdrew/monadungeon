<?php

declare(strict_types=1);

namespace App\Game\Field\DoctrineDBAL;

use App\Game\Item\Item;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class ItemMapType extends Type
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
     * @return ?array<string, Item>
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
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
        }

        $result = [];
        foreach ($decodedValue as $positionString => $itemData) {
            if (!\is_string($positionString)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
            }

            if ($itemData instanceof Item) {
                $result[$positionString] = $itemData;
            } elseif (\is_array($itemData)) {
                try {
                    /** @var array<string, mixed> $itemData */
                    $result[$positionString] = Item::fromAnything($itemData);
                } catch (\InvalidArgumentException $exception) {
                    throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                }
            } else {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
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
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
        }

        $converted = [];
        foreach ($value as $positionString => $item) {
            if (!\is_string($positionString)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
            }

            if ($item instanceof Item) {
                $converted[$positionString] = $item->toArray();
            } else {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', sprintf('array<string, %s>', Item::class)]);
            }
        }

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
