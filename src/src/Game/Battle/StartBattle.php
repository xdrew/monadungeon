<?php

declare(strict_types=1);

namespace App\Game\Battle;

use App\Game\Duel\DuelSide;
use App\Game\Field\FieldPlace;
use App\Game\Item\Item;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * @psalm-immutable
 * @implements Message<Battle>
 */
final readonly class StartBattle implements Message
{
    public function __construct(
        public Uuid $battleId,
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Item $monster,
        public FieldPlace $fromPosition,
        public FieldPlace $toPosition,
        /**
         * Karak: Regent — this Battle is one side of a Battle Arena duel (null for a monster fight).
         */
        public ?DuelSide $duel = null,
        /**
         * Baron Markul "Lightning Attack": he teleported in, so a fight that is not won ends in a chosen retreat.
         */
        public bool $lightning = false,
    ) {}
}
