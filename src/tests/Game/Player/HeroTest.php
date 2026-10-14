<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\CompleteReincarnation;
use App\Game\Player\GetReady;
use App\Game\Player\HeroPicked;
use App\Game\Player\HeroType;
use App\Game\Player\PickHero;
use App\Game\Player\Player;
use App\Game\Player\PlayerReincarnated;
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
 * Hero selection and the Warrior "Reincarnation" skill on the Player aggregate.
 */
#[CoversClass(Player::class)]
#[CoversClass(HeroType::class)]
final class HeroTest extends TestCase
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
    public function itCreatesPlayerWithHeroFromPlayerAddedEvent(): void
    {
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(
            gameId: $this->gameId,
            playerId: $this->playerId,
            hero: HeroType::WARRIOR,
        ));

        self::assertSame(HeroType::WARRIOR, $player->getHero());
        self::assertFalse($player->isAwaitingReincarnation());
        self::assertSame('warrior', $player->getPlayerStatus(new \App\Game\Player\GetPlayerStatus($this->playerId))['hero']);
    }

    #[Test]
    public function itPicksHeroBeforeReady(): void
    {
        $player = $this->createPlayer(null, 5);

        [, $messages] = handle(
            static fn(PickHero $command, MessageContext $context) => $player->pickHero($command, $context),
            new PickHero($this->gameId, $this->playerId, HeroType::WARRIOR),
        );

        self::assertSame(HeroType::WARRIOR, $player->getHero());
        self::assertCount(1, $messages);
        self::assertInstanceOf(HeroPicked::class, $messages[0]);
        self::assertSame(HeroType::WARRIOR, $messages[0]->hero);
    }

    #[Test]
    public function itCannotChangeHeroAfterReady(): void
    {
        $player = $this->createPlayer(null, 5);
        handle(
            static fn(GetReady $command, MessageContext $context) => $player->getReady($command, $context),
            new GetReady($this->gameId, $this->playerId),
        );

        $this->expectException(\DomainException::class);
        handle(
            static fn(PickHero $command, MessageContext $context) => $player->pickHero($command, $context),
            new PickHero($this->gameId, $this->playerId, HeroType::WARRIOR),
        );
    }

    #[Test]
    public function warriorAwaitsReincarnationInsteadOfBeingStunned(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 1);

        [, $messages] = handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        self::assertSame(0, $player->getHP());
        self::assertTrue($player->isAwaitingReincarnation());
        self::assertCount(1, $messages);
        self::assertInstanceOf(ReincarnationRequired::class, $messages[0]);
        self::assertTrue($messages[0]->turnId->equals($this->turnId));
        self::assertNull($this->findMessage($messages, PlayerStunned::class));
    }

    #[Test]
    public function playerWithoutHeroIsStunned(): void
    {
        $player = $this->createPlayer(null, 1);

        [, $messages] = handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        self::assertFalse($player->isAwaitingReincarnation());
        self::assertInstanceOf(PlayerStunned::class, $messages[0]);
        self::assertNull($this->findMessage($messages, ReincarnationRequired::class));
    }

    #[Test]
    public function warriorKeepsHpWhenNotOnLastHp(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 2);

        [, $messages] = handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        self::assertSame(1, $player->getHP());
        self::assertFalse($player->isAwaitingReincarnation());
        self::assertSame([], $messages);
    }

    #[Test]
    public function reincarnationReplenishesAllHp(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 1);
        handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        [, $messages] = handle(
            static fn(CompleteReincarnation $command, MessageContext $context) => $player->completeReincarnation($command, $context),
            new CompleteReincarnation($this->gameId, $this->playerId, FieldPlace::fromString('0,0')),
        );

        self::assertSame(Player::MAX_HP, $player->getHP());
        self::assertFalse($player->isAwaitingReincarnation());
        self::assertFalse($player->isDefeated());
        self::assertCount(1, $messages);
        self::assertInstanceOf(PlayerReincarnated::class, $messages[0]);
        self::assertSame('0,0', $messages[0]->position->toString());
    }

    #[Test]
    public function reincarnationRequiresPendingState(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 5);

        $this->expectException(\DomainException::class);
        handle(
            static fn(CompleteReincarnation $command, MessageContext $context) => $player->completeReincarnation($command, $context),
            new CompleteReincarnation($this->gameId, $this->playerId, FieldPlace::fromString('0,0')),
        );
    }

    #[Test]
    public function warriorSkillsAreDescribed(): void
    {
        self::assertSame(['warrior', 'thief'], HeroType::values());
        self::assertTrue(HeroType::WARRIOR->canRerollBattle());
        self::assertTrue(HeroType::WARRIOR->reincarnates());
        self::assertSame(['Double Attack', 'Reincarnation'], array_column(HeroType::WARRIOR->skills(), 'name'));
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

    /**
     * @param list<object> $messages
     * @param class-string $class
     */
    private function findMessage(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
