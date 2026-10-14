<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\CurseLifted;
use App\Game\Player\GetPlayerStatus;
use App\Game\Player\HeroType;
use App\Game\Player\Player;
use App\Game\Player\PlayerCursed;
use App\Game\Player\PlayerStunned;
use App\Game\Player\ReducePlayerHP;
use App\Game\Player\ReincarnationRequired;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;

use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Curse of Karak: a cursed player keeps the chosen hero for display but loses every skill.
 */
#[CoversClass(Player::class)]
final class CursedPlayerTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
    }

    #[Test]
    public function theCurseSwitchesTheHeroSkillsOffAndBackOn(): void
    {
        $player = $this->createPlayer(HeroType::WIZARD, 5);
        self::assertSame(HeroType::WIZARD, $player->getHero());
        self::assertFalse($player->isCursed());

        $player->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));

        self::assertNull($player->getHero(), 'No skills while cursed (the Wizard discards his fireballs like everyone else)');
        self::assertSame(HeroType::WIZARD, $player->getChosenHero(), 'The hero is still shown');
        self::assertTrue($player->isCursed());
        $status = $player->getPlayerStatus(new GetPlayerStatus($this->playerId));
        self::assertTrue($status['cursed']);
        self::assertSame('wizard', $status['hero']);

        $player->onCurseLifted(new CurseLifted($this->gameId, $this->playerId));

        self::assertSame(HeroType::WIZARD, $player->getHero());
        self::assertFalse($player->isCursed());
    }

    #[Test]
    public function aCursedWarriorDiesLikeTheOthers(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 1);
        $player->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));

        [, $messages] = handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        self::assertSame(0, $player->getHP());
        self::assertFalse($player->isAwaitingReincarnation());
        self::assertCount(1, $messages);
        self::assertInstanceOf(PlayerStunned::class, $messages[0]);
        self::assertNotInstanceOf(ReincarnationRequired::class, $messages[0]);
    }

    private function createPlayer(?HeroType $hero, int $hp): Player
    {
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
            hp: $hp,
            hero: $hero,
        ));

        return $player;
    }
}
