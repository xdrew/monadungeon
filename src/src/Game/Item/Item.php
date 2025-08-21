<?php

declare(strict_types=1);

namespace App\Game\Item;

use App\Game\Battle\BattleResult;
use App\Infrastructure\Uuid\Uuid;

final class Item
{
    public bool $guardDefeated = false;

    public readonly int $treasureValue;

    public readonly int $guardHP;

    public readonly Uuid $itemId;

    public readonly bool $endsGame;

    public function __construct(
        public readonly ItemName $name,
        public readonly ItemType $type,
        ?int $guardHP = null,
        ?int $treasureValue = null,
        ?bool $guardDefeated = null,
        ?Uuid $itemId = null,
        ?bool $endsGame = null,
    ) {
        if ($guardHP !== null) {
            $this->guardHP = $guardHP;
        } else {
            $this->guardHP = $name->getHp();
        }
        if ($treasureValue !== null) {
            $this->treasureValue = $treasureValue;
        } else {
            $this->treasureValue = $type->getTreasureValue();
        }
        if ($guardDefeated !== null) {
            $this->guardDefeated = $guardDefeated;
        }
        if ($endsGame !== null) {
            $this->endsGame = $endsGame;
        } else {
            $this->endsGame = $type->endsGame();
        }
        $this->itemId = $itemId ?? Uuid::v7();
    }

    /**
     * @param self|array<string, mixed> $anything
     */
    public static function fromAnything(self|array $anything): self
    {
        if ($anything instanceof self) {
            return $anything;
        }

        return self::fromArray($anything);
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        if (!isset($array['name']) || !isset($array['type'])) {
            throw new \InvalidArgumentException('Array must contain name and type keys');
        }

        if (!\is_string($array['name'])) {
            throw new \InvalidArgumentException('name must be a string');
        }

        if (!\is_string($array['type'])) {
            throw new \InvalidArgumentException('type must be a string');
        }

        $name = ItemName::from($array['name']);
        $type = ItemType::from($array['type']);
        $itemId = null;
        if (isset($array['itemId'])) {
            if (!\is_string($array['itemId'])) {
                throw new \InvalidArgumentException('itemId must be a string');
            }
            $itemId = Uuid::fromString($array['itemId']);
        }

        return new self(
            name: $name,
            type: $type,
            guardHP: isset($array['guardHP']) ? (int) $array['guardHP'] : null,
            treasureValue: isset($array['treasureValue']) ? (int) $array['treasureValue'] : null,
            guardDefeated: isset($array['guardDefeated']) ? (bool) $array['guardDefeated'] : null,
            itemId: $itemId,
            endsGame: isset($array['endsGame']) ? (bool) $array['endsGame'] : null,
        );
    }

    public static function createDragon(): self
    {
        return new self(
            name: ItemName::DRAGON,
            type: ItemType::RUBY_CHEST,
        );
    }

    public static function createRandom(): self
    {
        return self::createFromName(ItemName::getRandom()->value);
    }

    public static function createFallen(): self
    {
        return new self(
            name: ItemName::FALLEN,
            type: ItemType::CHEST,
        );
    }

    public static function createSkeletonKing(): self
    {
        return new self(
            name: ItemName::SKELETON_KING,
            type: ItemType::AXE,
        );
    }

    public static function createSkeletonWarrior(): self
    {
        return new self(
            name: ItemName::SKELETON_WARRIOR,
            type: ItemType::SWORD,
        );
    }

    public static function createGiantRat(): self
    {
        return new self(
            name: ItemName::GIANT_RAT,
            type: ItemType::DAGGER,
        );
    }

    public static function createGiantSpider(): self
    {
        return new self(
            name: ItemName::GIANT_SPIDER,
            type: ItemType::TELEPORT,
        );
    }

    public static function createMummy(): self
    {
        return new self(
            name: ItemName::MUMMY,
            type: ItemType::FIREBALL,
        );
    }

    public static function createSkeletonTurnkey(): self
    {
        return new self(
            name: ItemName::SKELETON_TURNKEY,
            type: ItemType::KEY,
        );
    }

    public static function createTreasureChest(): self
    {
        return new self(
            name: ItemName::TREASURE_CHEST,
            type: ItemType::CHEST,
        );
    }

    public static function createFromName(string $name): self
    {
        // Map common item names to configurations
        return match ($name) {
            'dragon' => self::createDragon(),
            'fallen' => self::createFallen(),
            'skeleton_warrior', 'skeleton' => self::createSkeletonWarrior(),
            'skeleton_king', 'skeletonKing' => self::createSkeletonKing(),
            'skeleton_turnkey' => self::createSkeletonTurnkey(),
            'giant_rat' => self::createGiantRat(),
            'giant_spider' => self::createGiantSpider(),
            'mummy' => self::createMummy(),
            'treasure_chest', 'treasureChest' => self::createTreasureChest(),
            'random', 'randomMonster' => self::createRandom(),
            default => new self(
                name: ItemName::tryFrom($name) ?? ItemName::getRandom(),
                type: ItemType::CHEST,
            ),
        };
    }

    public static function create(
        string $name,
        ItemType $type,
        int $guardHP = 0,
        int $treasureValue = 0,
        bool $endsGame = false,
    ): self {
        return new self(
            name: ItemName::tryFrom($name) ?? ItemName::getRandom(),
            type: $type,
            guardHP: $guardHP > 0 ? $guardHP : null,
            treasureValue: $treasureValue > 0 ? $treasureValue : null,
            guardDefeated: null,
            itemId: null,
            endsGame: $endsGame ? true : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name->value,
            'type' => $this->type->value,
            'itemId' => $this->itemId->toString(),
            'guardHP' => $this->guardHP,
            'guardDefeated' => $this->guardDefeated,
            'treasureValue' => $this->treasureValue,
            'endsGame' => $this->endsGame,
        ];
    }

    public function attack(int $damage): BattleResult
    {
        if ($damage > $this->guardHP) {
            return BattleResult::WIN;
        }
        if ($damage === $this->guardHP) {
            return BattleResult::DRAW;
        }

        return BattleResult::LOOSE;
    }

    public function defeatMonster(): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            guardHP: $this->guardHP,
            treasureValue: $this->treasureValue,
            guardDefeated: true,
            itemId: $this->itemId,
            endsGame: $this->endsGame,
        );
    }

    public function isLocked(): bool
    {
        // If there's no guard (guardHP = 0), the treasure is locked
        if ($this->guardHP === 0) {
            return true;
        }

        // If there is a guard, the treasure is locked until the guard is defeated
        return !$this->guardDefeated;
    }
}
