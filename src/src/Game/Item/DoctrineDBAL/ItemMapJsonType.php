<?php

declare(strict_types=1);

namespace App\Game\Item\DoctrineDBAL;

use App\Game\Item\Item;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

final class ItemMapJsonType extends Type
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
        $value = self::getTypeRegistry()->get(Types::JSON)->convertToPHPValue($value, $platform);

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
            }

            if ($item instanceof Item) {
                $result[$key] = $item;

                continue;
            }

            if (!\is_array($item)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
            }

            try {
                /** @var array<string, mixed> $item */
                $result[$key] = Item::fromArray($item);
            } catch (\InvalidArgumentException $exception) {
                throw ConversionException::conversionFailed($value, $this->getName(), $exception);
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
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
        }

        $converted = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
            }

            if ($item instanceof Item) {
                $converted[$key] = [
                    'name' => $item->name->value,
                    'type' => $item->type->value,
                    'guardHP' => $item->guardHP,
                    'treasureValue' => $item->treasureValue,
                    'guardDefeated' => $item->guardDefeated,
                    'itemId' => $item->itemId->toString(),
                    'endsGame' => $item->endsGame,
                ];

                continue;
            }

            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'array<string, Item>']);
        }

        return self::getTypeRegistry()->get(Types::JSON)->convertToDatabaseValue($converted, $platform);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
