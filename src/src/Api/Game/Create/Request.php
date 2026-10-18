<?php

declare(strict_types=1);

namespace App\Api\Game\Create;

use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $gameId,
        /**
         * Karak: Regent expansion.
         */
        public bool $regent = false,
        /**
         * Karak: Regent, Lord Karak variant (needs regent).
         */
        public bool $lordKarak = false,
        /**
         * Heroes are dealt at random (AI players are dealt one in any case).
         */
        public bool $randomHeroes = false,
        /** several humans at one screen */
        public bool $hotseat = false,
    ) {}
}
