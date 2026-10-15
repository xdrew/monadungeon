<?php

declare(strict_types=1);

namespace App\Tests\Game\GameLifecycle;

use App\Game\Battle\MummyDefeated;
use App\Game\Field\CursedRoomEntered;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetField;
use App\Game\Field\LordKarakRoomDiscovered;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\CursePlayer;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\LordKarakRevealed;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\GameLifecycle\StartGame;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\CurseLifted;
use App\Game\Player\GetPlayer;
use App\Game\Player\HeroType;
use App\Game\Player\Player;
use App\Game\Turn\PerformTurnAction;
use App\Game\Turn\TurnAction;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent, Lord Karak variant: the fifth Lord Karak room reveals the weakest hero as Lord Karak.
 */
#[CoversClass(Game::class)]
final class LordKarakGameTest extends TestCase
{
    private Uuid $gameId;

    /** @var array<string, Player> */
    private array $players = [];

    /** @var list<int> the Field's next dice */
    private array $dice = [];

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->players = [];
        $this->dice = [];
    }

    #[Test]
    public function theVariantNeedsTheExpansion(): void
    {
        try {
            handle(Game::create(...), new CreateGame(gameId: $this->gameId, lordKarak: true));
            self::fail('Lord Karak without Karak: Regent');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Karak: Regent', $e->getMessage());
        }

        [$game] = handle(Game::create(...), new CreateGame(gameId: Uuid::v7(), regent: true, lordKarak: true));
        self::assertTrue($game->isLordKarak());
        [$game] = handle(Game::create(...), new CreateGame(gameId: Uuid::v7(), regent: true));
        self::assertFalse($game->isLordKarak());
    }

    #[Test]
    public function heIsRevealedOnceAtTheFifthRoom(): void
    {
        $p1 = $this->player(HeroType::WARRIOR, treasures: [ItemType::CHEST]);
        $p2 = $this->player(HeroType::RANGER);
        $p3 = $this->player(HeroType::BARBARIAN, treasures: [ItemType::RUBY_CHEST]);
        $game = $this->startedGame([$p1, $p2, $p3]);
        $tester = $this->tester();

        [, $messages] = $tester->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 4));
        self::assertSame([], $messages, 'Four rooms are not enough');
        self::assertNull($game->getLordKarakPlayerId());

        [, $messages] = $tester->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));
        self::assertTrue($game->getLordKarakPlayerId()?->equals($p2), 'No treasure at all: the Ranger is the weakest');
        $revealed = $this->find($messages, LordKarakRevealed::class);
        self::assertInstanceOf(LordKarakRevealed::class, $revealed);
        self::assertTrue($revealed->playerId->equals($p2));
        self::assertSame([HeroType::WARRIOR, HeroType::BARBARIAN], $revealed->heroes, 'The other heroes, not his own');
        self::assertFalse($revealed->keepsOwn);
        self::assertSame('treasure', $revealed->criterion);
        self::assertNull($this->find($messages, CurseLifted::class), 'He was not cursed');

        $action = $this->find($messages, PerformTurnAction::class);
        self::assertInstanceOf(PerformTurnAction::class, $action);
        self::assertSame(TurnAction::LORD_KARAK, $action->action);
        self::assertSame($p2->toString(), $action->additionalData['player'] ?? null);
        self::assertSame(['warrior', 'barbarian'], $action->additionalData['heroes'] ?? null);

        $reveal = $game->getLordKarakReveal();
        self::assertNotNull($reveal);
        self::assertSame('treasure', $reveal['criterion']);
        self::assertSame([2, 0, 3], array_column($reveal['standings'], 'treasure'));

        [, $messages] = $tester->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 6));
        self::assertSame([], $messages, 'Only one Lord Karak per game');
        self::assertTrue($game->getLordKarakPlayerId()?->equals($p2));
    }

    #[Test]
    public function nothingHappensWithoutTheVariantOrWithOnePlayer(): void
    {
        $p1 = $this->player(HeroType::WARRIOR);
        $p2 = $this->player(HeroType::THIEF);
        $game = $this->startedGame([$p1, $p2], lordKarak: false);
        [, $messages] = $this->tester()->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));
        self::assertSame([], $messages);
        self::assertNull($game->getLordKarakPlayerId());

        $this->gameId = Uuid::v7();
        $solo = $this->startedGame([$this->player(HeroType::WARRIOR)]);
        [, $messages] = $this->tester()->handle($solo->onLordKarakRoomDiscovered(...), $this->room($p1, 5));
        self::assertSame([], $messages);
        self::assertNull($solo->getLordKarakPlayerId());
    }

    #[Test]
    public function theWeakestWeaponsLoseWhenTreasureTies(): void
    {
        // Equal treasure: weapon strength is the raw token value (Hanna's slot dagger counts, Markul's sword is 2)
        $p1 = $this->player(HeroType::BATTLE_MAGE, weapons: [ItemType::SWORD]);
        $p2 = $this->player(HeroType::ACROBAT, weapons: [ItemType::DAGGER], spells: [ItemType::DAGGER]);
        $p3 = $this->player(HeroType::WARRIOR, weapons: [ItemType::DAGGER], spells: [ItemType::FIREBALL]);
        $game = $this->startedGame([$p1, $p2, $p3]);

        $this->tester()->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));

        self::assertTrue($game->getLordKarakPlayerId()?->equals($p3));
        $reveal = $game->getLordKarakReveal();
        self::assertNotNull($reveal);
        self::assertSame('weapons', $reveal['criterion']);
        self::assertSame([2, 2, 1], array_column($reveal['standings'], 'weapons'));
        self::assertSame([0, 0, 1], array_column($reveal['standings'], 'items'), 'The slot dagger is a weapon, the fireball an item');
    }

    #[Test]
    public function theFewestItemsLoseWhenWeaponsTie(): void
    {
        $p1 = $this->player(HeroType::WARRIOR, weapons: [ItemType::DAGGER], keys: true);
        $p2 = $this->player(HeroType::THIEF, weapons: [ItemType::DAGGER]);
        $game = $this->startedGame([$p1, $p2]);

        [, $messages] = $this->tester()->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));
        self::assertTrue($game->getLordKarakPlayerId()?->equals($p2));
        self::assertSame('items', $game->getLordKarakReveal()['criterion'] ?? null);

        $revealed = $this->find($messages, LordKarakRevealed::class);
        self::assertInstanceOf(LordKarakRevealed::class, $revealed);
        self::assertTrue($revealed->keepsOwn, 'With 2 players he keeps his own abilities');
        self::assertSame([HeroType::WARRIOR, HeroType::THIEF], $revealed->heroes);
    }

    #[Test]
    public function aFullTieIsSettledByTheDiceAndAVirtualPlayerCanBeChosen(): void
    {
        // The Field's dice (scripted in test games): round 1: 4, 2, 2 → p2 and p3 roll again; round 2: 5, 1 → p3
        $this->dice = [4, 2, 2, 5, 1];
        $p1 = $this->player(HeroType::WARRIOR);
        $p2 = $this->player(HeroType::THIEF);
        $p3 = $this->player(null, isAi: true);
        $game = $this->startedGame([$p1, $p2, $p3]);

        [, $messages] = $this->tester()->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));

        self::assertTrue($game->getLordKarakPlayerId()?->equals($p3));
        $reveal = $game->getLordKarakReveal();
        self::assertNotNull($reveal);
        self::assertSame('roll', $reveal['criterion']);
        self::assertSame([[4], [2, 5], [2, 1]], array_column($reveal['standings'], 'rolls'));
        $revealed = $this->find($messages, LordKarakRevealed::class);
        self::assertInstanceOf(LordKarakRevealed::class, $revealed);
        self::assertSame([HeroType::WARRIOR, HeroType::THIEF], $revealed->heroes);
    }

    #[Test]
    public function theCurseIsLiftedFromLordKarak(): void
    {
        $p1 = $this->player(HeroType::WARRIOR, treasures: [ItemType::CHEST]);
        $p2 = $this->player(HeroType::RANGER);
        $game = $this->startedGame([$p1, $p2]);
        $tester = $this->tester();
        $turnId = $game->getCurrentTurnId();
        self::assertNotNull($turnId);

        // The Ranger carries both curses: the mummy token and a Cursed Room
        $tester->handle($game->onMummyDefeated(...), new MummyDefeated($this->gameId, $p1, $turnId));
        $tester->handle($game->cursePlayer(...), new CursePlayer($this->gameId, $p1, $turnId, $p2));
        $tester->handle($game->onCursedRoomEntered(...), new CursedRoomEntered($this->gameId, $p2, FieldPlace::fromString('1,0'), 2, true, $turnId));
        self::assertTrue($game->isPlayerCursed($p2));

        [, $messages] = $tester->handle($game->onLordKarakRoomDiscovered(...), $this->room($p1, 5));

        self::assertTrue($game->getLordKarakPlayerId()?->equals($p2));
        self::assertFalse($game->isPlayerCursed($p2));
        self::assertNull($game->getCursedPlayerId());
        self::assertSame([], $game->getRoomCursedPlayerIds());
        $lifted = $this->find($messages, CurseLifted::class);
        self::assertInstanceOf(CurseLifted::class, $lifted);
        self::assertTrue($lifted->playerId->equals($p2));
    }

    /**
     * @param list<Uuid> $players
     */
    private function startedGame(array $players, bool $lordKarak = true): Game
    {
        [$game] = handle(Game::create(...), new CreateGame(gameId: $this->gameId, regent: true, lordKarak: $lordKarak));
        $tester = MessageBusTester::create();
        foreach ($players as $playerId) {
            $tester->handle($game->addPlayer(...), new AddPlayer($this->gameId, $playerId));
        }
        $tester->handle($game->start(...), new StartGame($this->gameId, new \DateTimeImmutable()));

        return $game;
    }

    /**
     * @param list<ItemType> $treasures
     * @param list<ItemType> $weapons
     * @param list<ItemType> $spells
     */
    private function player(?HeroType $hero, array $treasures = [], array $weapons = [], array $spells = [], bool $keys = false, bool $isAi = false): Uuid
    {
        $playerId = Uuid::v7();
        [$player] = handle(Player::onPlayerAddedToGame(...), new PlayerAdded(
            gameId: $this->gameId,
            playerId: $playerId,
            isAi: $isAi,
            hero: $hero,
        ));
        $item = static fn(ItemType $type): Item => new Item(ItemName::GIANT_RAT, $type, 5, null, true);
        (new \ReflectionProperty(Player::class, 'inventory'))->setValue($player, [
            ItemCategory::KEY->value => $keys ? [$item(ItemType::KEY)] : [],
            ItemCategory::WEAPON->value => array_map($item, $weapons),
            ItemCategory::SPELL->value => array_map($item, $spells),
            ItemCategory::TREASURE->value => array_map($item, $treasures),
        ]);
        $this->players[$playerId->toString()] = $player;

        return $playerId;
    }

    private function tester(): MessageBusTester
    {
        $players = $this->players;
        $dice = &$this->dice;

        return MessageBusTester::create(
            static fn(GetPlayer $query): Player => $players[$query->playerId->toString()],
            static fn(GetField $_query): object => new class ($dice) {
                /**
                 * @param list<int> $dice
                 */
                public function __construct(private array &$dice) {}

                public function getNextDiceRoll(int $min, int $max): int
                {
                    return array_shift($this->dice) ?? random_int($min, $max);
                }
            },
        );
    }

    private function room(Uuid $playerId, int $count): LordKarakRoomDiscovered
    {
        return new LordKarakRoomDiscovered($this->gameId, $playerId, FieldPlace::fromString('1,0'), $count);
    }

    /**
     * @template T of object
     *
     * @param list<object> $messages
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
