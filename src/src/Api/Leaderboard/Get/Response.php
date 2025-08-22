<?php

declare(strict_types=1);

namespace App\Api\Leaderboard\Get;

final readonly class Response
{
    public function __construct(
        public array $entries,
        public array $pagination,
        public array $sorting,
        public ?array $currentPlayer = null,
    ) {}
}