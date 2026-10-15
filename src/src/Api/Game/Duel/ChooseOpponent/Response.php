<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\ChooseOpponent;

use App\Infrastructure\Uuid\Uuid;

final readonly class Response
{
    /**
     * @param array<string, mixed>|null $battleInfo The challenger's roll (the duel modal), null when it already went on
     * @param array<string, mixed>|null $duel The duel projection (state.duel)
     */
    public function __construct(
        public Uuid $gameId,
        public ?array $battleInfo,
        public ?array $duel,
        public bool $success = true,
    ) {}
}
