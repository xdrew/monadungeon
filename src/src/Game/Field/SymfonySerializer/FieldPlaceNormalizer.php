<?php

declare(strict_types=1);

namespace App\Game\Field\SymfonySerializer;

use App\Game\Field\FieldPlace;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class FieldPlaceNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [FieldPlace::class => true];
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        if (!$object instanceof FieldPlace) {
            throw new \InvalidArgumentException('The object must be an instance of ' . FieldPlace::class);
        }

        return $object->toString();
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof FieldPlace;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): FieldPlace
    {
        if (\is_string($data)) {
            return FieldPlace::fromString($data);
        }

        // Handle {x, y} format from frontend
        if (\is_array($data) && isset($data['x'], $data['y']) && is_numeric($data['x']) && is_numeric($data['y'])) {
            $validData = ['positionX' => (int) $data['x'], 'positionY' => (int) $data['y']];

            return FieldPlace::fromArray($validData);
        }

        // Handle {positionX, positionY} format (original)
        if (\is_array($data) && isset($data['positionX'], $data['positionY']) && is_numeric($data['positionX']) && is_numeric($data['positionY'])) {
            $validData = ['positionX' => (int) $data['positionX'], 'positionY' => (int) $data['positionY']];

            return FieldPlace::fromArray($validData);
        }

        throw new \InvalidArgumentException('Cannot denormalize data to FieldPlace');
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === FieldPlace::class;
    }
}
