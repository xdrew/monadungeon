<?php

declare(strict_types=1);

namespace App\Game\Item;

enum ItemType: string
{
    case KEY = 'key';
    case CHEST = 'chest';
    case RUBY_CHEST = 'ruby_chest';
    case DAGGER = 'dagger';
    case SWORD = 'sword';
    case AXE = 'axe';
    case FIREBALL = 'fireball';
    case TELEPORT = 'teleport';

    public static function getRandomWeapon(): self
    {
        $weapons = [
            self::DAGGER,
            self::SWORD,
            self::AXE,
        ];

        return $weapons[array_rand($weapons)];
    }

    public static function getRandomSpell(): self
    {
        $spells = [
            self::FIREBALL,
            self::TELEPORT,
        ];

        return $spells[array_rand($spells)];
    }

    public static function getRandom(): self
    {
        $items = array_filter(
            self::cases(),
            static fn(self $item) => $item !== self::RUBY_CHEST,
        );

        return $items[array_rand($items)];
    }

    public static function fromString(string $type): self
    {
        return self::from($type);
    }

    public function getCategory(): ItemCategory
    {
        return match ($this) {
            self::CHEST, self::RUBY_CHEST => ItemCategory::TREASURE,
            self::KEY => ItemCategory::KEY,
            self::DAGGER, self::SWORD, self::AXE => ItemCategory::WEAPON,
            self::FIREBALL, self::TELEPORT => ItemCategory::SPELL,
        };
    }

    public function getTreasureValue(): int
    {
        return match ($this) {
            self::CHEST => 2,
            self::RUBY_CHEST => 3,
            default => 0,
        };
    }

    public function getDamage(): int
    {
        return match ($this) {
            self::DAGGER => 1,
            self::SWORD => 2,
            self::AXE => 3,
            self::FIREBALL => 1,
            default => 0,
        };
    }

    public function isDisposable(): bool
    {
        return match ($this->getCategory()) {
            ItemCategory::SPELL => true,
            default => false,
        };
    }

    public function endsGame(): bool
    {
        return match ($this) {
            self::RUBY_CHEST => true,
            default => false,
        };
    }
}
