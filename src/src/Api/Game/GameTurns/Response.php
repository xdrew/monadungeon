<?php

declare(strict_types=1);

namespace App\Api\Game\GameTurns;

final readonly class Response
{
    /**
     * @param list<array<array-key, mixed>> $turns
     */
    private function __construct(
        public string $gameId,
        public array $turns,
    ) {}

    /**
     * Create response from game turns.
     *
     * @param list<array<array-key, mixed>> $turns
     */
    public static function fromGameTurns(string $gameId, array $turns): self
    {
        $formattedTurns = [];

        foreach ($turns as $turn) {
            if (isset($turn['actions'])) {
                $turn['actions'] = json_decode((string) $turn['actions'], true);
            }
            $formattedTurns[] = $turn;
        }

        return new self(
            gameId: $gameId,
            turns: $formattedTurns,
        );
    }
}
