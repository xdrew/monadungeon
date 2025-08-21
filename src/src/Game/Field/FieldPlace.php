<?php

declare(strict_types=1);

namespace App\Game\Field;

final class FieldPlace
{
    public function __construct(
        public int $positionX,
        public int $positionY,
    ) {}

    /**
     * @param string|array{positionX: int, positionY: int}|self $anything
     */
    public static function fromAnything(string|array|self $anything): self
    {
        if ($anything instanceof self) {
            return $anything;
        }
        if (\is_array($anything)) {
            return self::fromArray($anything);
        }

        return self::fromString($anything);
    }

    public static function fromString(string $string): self
    {
        return new self(...array_map('intval', array_map('trim', explode(',', $string))));
    }

    /**
     * @param array{positionX: int, positionY: int} $array
     */
    public static function fromArray(array $array): self
    {
        return new self($array['positionX'], $array['positionY']);
    }

    public function equals(self $fieldPlace): bool
    {
        return $this->positionX === $fieldPlace->positionX && $this->positionY === $fieldPlace->positionY;
    }

    public function toString(): string
    {
        return $this->positionX . ',' . $this->positionY;
    }

    public function getSiblingBySide(TileSide $side): self
    {
        return $this->getAllSiblingsBySides()[$side->value];
    }

    /**
     * @return array{0:FieldPlace, 1:FieldPlace, 2:FieldPlace, 3:FieldPlace}
     */
    public function getAllSiblingsBySides(): array
    {
        return [
            TileSide::TOP->value => new self($this->positionX, $this->positionY - 1),
            TileSide::RIGHT->value => new self($this->positionX + 1, $this->positionY),
            TileSide::LEFT->value => new self($this->positionX - 1, $this->positionY),
            TileSide::BOTTOM->value => new self($this->positionX, $this->positionY + 1),
        ];
    }
}
