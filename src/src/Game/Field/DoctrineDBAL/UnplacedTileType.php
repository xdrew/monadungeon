<?php

declare(strict_types=1);

namespace App\Game\Field\DoctrineDBAL;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

final class UnplacedTileType extends Type
{
    public function getName(): string
    {
        return self::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        // Decode JSON if it's a string
        if (is_string($value)) {
            $data = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ConversionException::conversionFailed($value, $this->getName());
            }
        } else {
            $data = $value;
        }
        
        if (!is_array($data)) {
            return null;
        }

        // Convert tileId string back to Uuid object
        if (isset($data['tileId']) && is_string($data['tileId'])) {
            $data['tileId'] = Uuid::fromString($data['tileId']);
        }

        // Convert fieldPlace array back to FieldPlace object
        if (isset($data['fieldPlace']) && is_array($data['fieldPlace'])) {
            $data['fieldPlace'] = new FieldPlace(
                $data['fieldPlace']['positionX'],
                $data['fieldPlace']['positionY']
            );
        }

        // The tile data (orientation, room, features) is kept as-is
        // It will be stored as an array with those properties

        return $data;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = $value;

        // Convert Uuid object to string for storage
        if (isset($data['tileId']) && $data['tileId'] instanceof Uuid) {
            $data['tileId'] = $data['tileId']->toString();
        }

        // Convert FieldPlace object to array for storage
        if (isset($data['fieldPlace']) && $data['fieldPlace'] instanceof FieldPlace) {
            $data['fieldPlace'] = [
                'positionX' => $data['fieldPlace']->positionX,
                'positionY' => $data['fieldPlace']->positionY,
            ];
        }

        // Encode to JSON
        $json = json_encode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ConversionException::conversionFailed($data, $this->getName());
        }
        
        return $json;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}