<?php

declare(strict_types=1);

namespace App\Game\Player;

use App\Game\Field\FieldPlace;
use App\Infrastructure\Uuid\Uuid;
use Telephantast\Message\Message;

/**
 * Command to use a spell from player's inventory.
 * For the Portal of Healing (teleport spell), targetPosition must be a revealed healing fountain;
 * targetPlayerId is the player carried to the fountain (the caster when null).
 * @psalm-immutable
 * @implements Message<void>
 */
final readonly class UseSpell implements Message
{
    public function __construct(
        public Uuid $gameId,
        public Uuid $playerId,
        public Uuid $turnId,
        public Uuid $spellId,
        public ?FieldPlace $targetPosition = null,
        public ?Uuid $targetPlayerId = null,
    ) {}
}
