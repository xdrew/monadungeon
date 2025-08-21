<?php

declare(strict_types=1);

namespace App\Game\Bag\DoctrineDBAL;

use App\Game\Item\Item;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class InventoryJsonType extends Type
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
     * @return ?array<string, array<Item>>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
        }

        $result = [];
        foreach ($value as $category => $items) {
            if (!\is_string($category)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
            }

            if (!\is_array($items)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
            }

            $result[$category] = array_map(
                function (mixed $item) use ($value): Item {
                    if ($item instanceof Item) {
                        return $item;
                    }

                    if (!\is_array($item)) {
                        throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
                    }

                    try {
                        /** @var array<string, mixed> $item */
                        return Item::fromArray($item);
                    } catch (\InvalidArgumentException $exception) {
                        throw ConversionException::conversionFailed($value, $this->getName(), $exception);
                    }
                },
                $items,
            );
        }

        return $result;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
        }

        $converted = [];
        foreach ($value as $category => $items) {
            if (!\is_string($category)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
            }

            if (!\is_array($items)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
            }

            $converted[$category] = array_map(
                function (mixed $item) use ($value): array {
                    if ($item instanceof Item) {
                        return [
                            'name' => $item->name->value,
                            'type' => $item->type->value,
                            'guardHP' => $item->guardHP,
                            'treasureValue' => $item->treasureValue,
                            'guardDefeated' => $item->guardDefeated,
                            'itemId' => $item->itemId->toString(),
                            'endsGame' => $item->endsGame,
                        ];
                    }

                    throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, array<Item>>']);
                },
                $items,
            );
        }

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
