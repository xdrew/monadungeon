<?php

declare(strict_types=1);

namespace App\Api\Game\Duel\TakeLoot;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $gameId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $playerId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $turnId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $duelId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $itemId,
        /** the winner's item to leave on the arena when the pack is full */
        #[Assert\Uuid]
        public ?Uuid $replaceItemId = null,
    ) {}
}
