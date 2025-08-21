<?php

declare(strict_types=1);

namespace App\Game\Testing;

/**
 * Test mode configuration for predictable E2E testing.
 */
final class TestMode
{
    private static ?self $instance = null;

    private bool $enabled = false;

    /** @var array<int, int> */
    private array $diceRolls = [];

    private int $diceRollIndex = 0;

    /** @var array<string, array<array-key, mixed>> */
    private array $fixedDecks = [];

    /** @var array<string, array<array-key, string>> */
    private array $fixedBags = [];

    /** @var array<string, int> */
    private array $fixedOrientations = [];

    /** @var array<string, array<string, int>> */
    private array $playerHpConfigs = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->reset();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        $this->diceRolls = [];
        $this->diceRollIndex = 0;
        $this->fixedDecks = [];
        $this->fixedBags = [];
        $this->fixedOrientations = [];
        $this->playerHpConfigs = [];
    }

    public function setDiceRolls(array $rolls): void
    {
        $this->diceRolls = array_values(array_map('intval', $rolls));
        $this->diceRollIndex = 0;
    }

    public function getNextDiceRoll(int $min, int $max): int
    {
        if (!$this->enabled || \count($this->diceRolls) === 0) {
            return random_int($min, $max);
        }

        $roll = $this->diceRolls[$this->diceRollIndex % \count($this->diceRolls)];
        ++$this->diceRollIndex;

        // Ensure roll is within bounds
        return max($min, min($max, $roll));
    }

    public function setFixedDeck(string $gameId, array $tiles): void
    {
        $this->fixedDecks[$gameId] = $tiles;
    }

    public function getFixedDeck(string $gameId): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->fixedDecks[$gameId] ?? null;
    }

    /**
     * @param array<array-key, string> $items
     */
    public function setFixedBag(string $gameId, array $items): void
    {
        $this->fixedBags[$gameId] = $items;
    }

    public function getFixedBag(string $gameId): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->fixedBags[$gameId] ?? null;
    }

    public function setFixedOrientation(string $key, int $orientation): void
    {
        $this->fixedOrientations[$key] = $orientation;
    }

    public function getFixedOrientation(string $key): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->fixedOrientations[$key] ?? null;
    }

    public function getRandomArrayKey(array $array, string $context = ''): null|int|string
    {
        if (!$this->enabled || \count($array) === 0) {
            return array_rand($array);
        }

        // For test mode, always return first available option for consistency
        return array_key_first($array);
    }

    public function setPlayerHp(string $gameId, string $playerId, int $hp): void
    {
        if (!isset($this->playerHpConfigs[$gameId])) {
            $this->playerHpConfigs[$gameId] = [];
        }
        $this->playerHpConfigs[$gameId][$playerId] = $hp;
    }

    public function getPlayerHp(string $gameId, string $playerId): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->playerHpConfigs[$gameId][$playerId] ?? null;
    }

    /**
     * Get all dice rolls without consuming them (for persistence).
     */
    public function getAllDiceRolls(): array
    {
        return $this->diceRolls;
    }
}
