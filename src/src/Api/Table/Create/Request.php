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
        /**
         * Dev/test only, with the test mode on: the deck, the bag and the dice of the new table
         * ({tileSequence, itemSequence, diceRolls}, as in /test/setup-game); ignored elsewhere.
         *
         * @var array{tileSequence?: list<mixed>, itemSequence?: list<mixed>, diceRolls?: list<int>}|null
         */
        public ?array $test = null,
    ) {}
}
