<?php

declare(strict_types=1);

namespace App\Game\Deck;

use App\Game\Field\TileFeature;
use App\Game\Field\TileOrientation;

final readonly class DeckTile implements \JsonSerializable
{
    /**
     * @param array<TileFeature> $features
     */
    private function __construct(
        public TileOrientation $orientation,
        public bool $room = false,
        public array $features = [],
    ) {}

    /**
     * @param positive-int $amount
     * @param array<TileFeature> $features
     * @return ($amount is 1 ? self : list<self>)
     */
    public static function create(TileOrientation $orientation, bool $room = false, int $amount = 1, array $features = []): self|array
    {
        if ($amount === 1) {
            return new self(orientation: $orientation->rotateRandom(), room: $room, features: $features);
        }
        $result = [];
        for ($i = 0; $i < $amount; ++$i) {
            $result[] = new self(orientation: $orientation->rotateRandom(), room: $room, features: $features);
        }

        return $result;
    }

    public static function fromString(string $input): self
    {
        $parts = explode('|', $input);
        $orientationString = $parts[0];
        $roomValue = $parts[1];
        $orientation = TileOrientation::fromString($orientationString);
        $room = $roomValue === '1';
        $features = [];
        if (isset($parts[2])) {
            $featureStrings = explode(',', $parts[2]);
            foreach ($featureStrings as $featureString) {
                if ($featureString !== '') {
                    $features[] = TileFeature::from($featureString);
                }
            }
        }

        return new self(orientation: $orientation, room: $room, features: $features);
    }

    public static function createFromName(string $name): self
    {
        // Map common tile names to configurations
        return match ($name) {
            'fourSideRoom' => new self(TileOrientation::fourSide(), true),
            'fourSide' => new self(TileOrientation::fourSide(), false),
            'threeSideRoom' => new self(TileOrientation::threeSide(), true),
            'threeSide' => new self(TileOrientation::threeSide(), false),
            'twoSideCornerRoom' => new self(TileOrientation::twoSideCorner(), true),
            'twoSideCorner' => new self(TileOrientation::twoSideCorner(), false),
            'twoSideStraightRoom' => new self(TileOrientation::twoSideStraight(), true),
            'twoSideStraight' => new self(TileOrientation::twoSideStraight(), false),
            default => new self(TileOrientation::fourSide(), false),
        };
    }

    public function toString(): string
    {
        $featureStrings = array_map(static fn(TileFeature $f) => $f->value, $this->features);

        return sprintf('%s|%d|%s', $this->orientation->toString(), $this->room ? 1 : 0, implode(',', $featureStrings));
    }

    public function jsonSerialize(): mixed
    {
        return $this->toString();
    }
}
