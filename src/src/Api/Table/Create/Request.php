<?php

declare(strict_types=1);

namespace App\Api\Table\Create;

use App\Game\GameLifecycle\CreateTable;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class Request
{
    public function __construct(
        #[Assert\Range(min: CreateTable::MIN_SEATS, max: CreateTable::MAX_SEATS)]
        public int $seats = 4,
        public bool $regent = false,
        public bool $lordKarak = false,
        public bool $randomHeroes = false,
    ) {}
}
