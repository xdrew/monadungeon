<?php

declare(strict_types=1);

namespace App\Game\Field;

final class TileOrientation implements \JsonSerializable
{
    /**
     * @var array<string, list{string, string}>
     * @psalm-var array{
     *   '1111': list{string, string},
     *   '1010': list{string, string},
     *   '0101': list{string, string},
     *   '0011': list{string, string},
     *   '0110': list{string, string},
     *   '1001': list{string, string},
     *   '1100': list{string, string},
     *   '1110': list{string, string},
     *   '1011': list{string, string},
     *   '0111': list{string, string},
     *   '1101': list{string, string}
     * }
     */
    private static array $charactersMapping = [
        '1111' => ['╬', '╋'], // All sides open (crossroad)
        '1010' => ['║', '┃'], // Top and bottom open (vertical straight)
        '0101' => ['═', '━'], // Left and right open (horizontal straight)
        '0011' => ['╗', '┓'], // Right and bottom open (top-left corner)
        '0110' => ['╔', '┏'], // Bottom and left open (top-right corner)
        '1001' => ['╝', '┛'], // Top and left open (bottom-right corner) - This was swapped
        '1100' => ['╚', '┗'], // Top and right open (bottom-left corner) - This was swapped
        '1110' => ['╠', '┣'], // Top, right, bottom open (T-junction, opening to the right)
        '1011' => ['╣', '┫'], // Top, bottom, left open (T-junction, opening to the left)
        '0111' => ['╦', '┳'], // Right, bottom, left open (T-junction, opening down)
        '1101' => ['╩', '┻'], // Top, right, left open (T-junction, opening up)
    ];

    /**
     * @var bool[]
     */
    private array $orientation;

    public function __construct(
        bool $top = false,
        bool $right = false,
        bool $bottom = false,
        bool $left = false,
    ) {
        $this->orientation = [$top, $right, $bottom, $left];
    }

    public static function fromString(string $string): self
    {
        // Handle named orientations
        if (\in_array($string, ['fourSide', 'threeSide', 'twoSideCorner', 'twoSideStraight'], true)) {
            return match ($string) {
                'fourSide' => self::fourSide(),
                'threeSide' => self::threeSide(),
                'twoSideCorner' => self::twoSideCorner(),
                'twoSideStraight' => self::twoSideStraight(),
            };
        }

        // Handle array format
        $string = trim($string, '[]');
        $values = array_map('trim', explode(',', $string));

        return new self(...array_map(static fn(string $item): bool => $item === 'true', $values));
    }

    /**
     * @param bool[] $array
     */
    public static function fromArray(array $array): self
    {
        return new self(...$array);
    }

    public static function fourSide(): self
    {
        return new self(top: true, right: true, bottom: true, left: true);
    }

    public static function threeSide(): self
    {
        return new self(top: true, right: true, bottom: true, left: false);
    }

    public static function twoSideStraight(): self
    {
        return new self(top: true, right: false, bottom: true, left: false);
    }

    public static function twoSideCorner(): self
    {
        return new self(top: true, right: true, bottom: false, left: false);
    }

    public static function random(): self
    {
        return match (random_int(0, 3)) {
            0 => self::fourSide(),
            1 => self::threeSide(),
            2 => self::twoSideStraight(),
            3 => self::twoSideCorner(),
        };
    }

    public static function fromCharacter(string $character): self
    {
        foreach (self::$charactersMapping as $orientation => $characters) {
            if (\in_array($character, $characters, true)) {
                return new self(...array_map('boolval', str_split((string) $orientation)));
            }
        }

        throw new \InvalidArgumentException('Invalid character');
    }

    private static function parseOrientation(string $orientation): self
    {
        // Parse comma-separated boolean values
        $parts = explode(',', $orientation);
        if (\count($parts) !== 4) {
            throw new \InvalidArgumentException('Invalid orientation string');
        }

        $sides = array_map(static fn($part) => $part === 'true', $parts);

        return new self(...$sides);
    }

    public function toString(): string
    {
        return implode(',', array_map(static fn($side) => $side ? 'true' : 'false', $this->orientation));
    }

    public function getOrientationForTopSide(TileSide $tileSideOnTop): self
    {
        // For proper rotation logic:
        // - If TOP becomes the new top, no change (0 degree rotation)
        // - If RIGHT becomes the new top, 270 degree rotation
        // - If BOTTOM becomes the new top, 180 degree rotation
        // - If LEFT becomes the new top, 90 degree rotation

        // The rotation makes a side become the new TOP
        // So when we rotate to make RIGHT the new TOP,
        // the original array [TOP, RIGHT, BOTTOM, LEFT] becomes [RIGHT, BOTTOM, LEFT, TOP]

        $beginningArray = \array_slice($this->orientation, $tileSideOnTop->value);
        $endingArray = \array_slice($this->orientation, 0, $tileSideOnTop->value);

        $newOrientation = array_merge($beginningArray, $endingArray);

        return new self(...$newOrientation);
    }

    public function getCharacter(bool $room): string
    {
        $key = implode('', array_map('intval', $this->orientation));

        return self::$charactersMapping[$key][$room ? 0 : 1] ?? '';
    }

    /**
     * @return bool[]
     */
    public function getOrientation(): array
    {
        return $this->orientation;
    }

    public function rotateRandom(): self
    {
        return new self(...$this->getOrientationForTopSide(TileSide::getRandomSide())->getOrientation());
    }

    public function isOpenedSide(TileSide $tileSide): bool
    {
        $key = $tileSide->value;

        /** @var bool */
        return $this->orientation[$key];
    }

    public function hasCommonOpenedSides(self $orientation): bool
    {
        foreach ($orientation->getOrientation() as $key => $value) {
            if ($value === true && $this->orientation[$key] === true) {
                return true;
            }
        }

        return false;
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Clone method to ensure proper deep copying of orientation.
     */
    public function __clone()
    {
        // Ensure orientation array is cloned
        $this->orientation = [...$this->orientation];
    }
}
