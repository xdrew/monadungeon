<?php

declare(strict_types=1);

namespace App\Infrastructure\Uuid\SymfonySerializer;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class UuidNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Uuid::class => true];
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        /** @var Uuid $object */
        return $object->toString();
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Uuid;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Uuid
    {
        if (!\is_string($data)) {
            throw new UnexpectedValueException();
        }

        return Uuid::fromString($data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Uuid::class;
    }
}
