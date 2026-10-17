<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\Field\FieldPlace;
use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Player\CompleteReincarnation;
use App\Game\Player\GetPlayerStatus;
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
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const array FLAG_KEYS = [
        'canRerollBattle', 'reincarnates', 'winsTies', 'canSneakPastMonsters', 'rerollsOnes', 'continuesTurnOnSix',
        'sacrificesHp', 'canMagicSwap', 'keepsSpellsInCombat', 'walksThroughWalls', 'fightsBetterOnFirstMove',
        'drawsTwoTokens', 'berserk', 'choosesHealing', 'tireless', 'masterOfSword', 'lightningAttack',
        'throwsDaggers', 'sprints', 'bearAttack', 'listens',
    ];

    private Uuid $gameId;

    private Uuid $playerId;

    private Uuid $turnId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
    }

    /**
     * @return iterable<string, array{HeroType, string, list<string>, list<string>}>
     */
    public static function regentHeroes(): iterable
    {
        yield 'barbarian' => [HeroType::BARBARIAN, 'Valduk, the Barbarian', ['berserk', 'choosesHealing', 'tireless'], ['Berserk', 'Tireless']];
        yield 'battle mage' => [HeroType::BATTLE_MAGE, 'Baron Markul, the Battle Mage', ['masterOfSword', 'lightningAttack'], ['Sword Master', 'Lightning Attack']];
        yield 'acrobat' => [HeroType::ACROBAT, 'Hanna, the Acrobat', ['throwsDaggers', 'sprints'], ['Dagger Throwing', 'Sprint']];
        yield 'ranger' => [HeroType::RANGER, 'Lady Lorraine, the Ranger', ['bearAttack', 'listens'], ['Bear Attack', 'Listening']];
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
        self::assertSame('warrior', $player->getPlayerStatus(new GetPlayerStatus($this->playerId))['hero']);
    }

    #[Test]
    public function itPicksHeroBeforeReady(): void
    {
        $player = $this->createPlayer(null, 5);

        [, $messages] = $this->gameTester()->handle(
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
        $this->gameTester()->handle(
            static fn(PickHero $command, MessageContext $context) => $player->pickHero($command, $context),
            new PickHero($this->gameId, $this->playerId, HeroType::WARRIOR),
        );
    }

    #[Test]
    public function aHeroCannotBePickedWhenHeroesAreDealtAtRandom(): void
    {
        $player = $this->createPlayer(null, 5);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Heroes are dealt at random in this game');
        $this->gameTester(randomHeroes: true)->handle(
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
    public function reincarnationHealsToTheHerosOwnMaximum(): void
    {
        $player = $this->createPlayer(HeroType::WARRIOR, 1);
        $player->setTestMaxHp(3);
        handle(
            static fn(ReducePlayerHP $command, MessageContext $context) => $player->reduceHP($command, $context),
            new ReducePlayerHP($this->playerId, $this->gameId, 1, $this->turnId),
        );

        handle(
            static fn(CompleteReincarnation $command, MessageContext $context) => $player->completeReincarnation($command, $context),
            new CompleteReincarnation($this->gameId, $this->playerId, FieldPlace::fromString('0,0')),
        );

        self::assertSame(3, $player->getHP());
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
        self::assertSame(['warrior', 'thief', 'swordsman', 'warlock', 'wizard', 'oracle', 'barbarian', 'battle_mage', 'acrobat', 'ranger'], HeroType::values());
        self::assertTrue(HeroType::WARRIOR->canRerollBattle());
        self::assertTrue(HeroType::WARRIOR->reincarnates());
        self::assertSame(['Double Attack', 'Reincarnation'], array_column(HeroType::WARRIOR->skills(), 'name'));
    }

    #[Test]
    public function swordsmanSkillsAreDescribed(): void
    {
        self::assertSame('Victorius, the Swordsman', HeroType::SWORDSMAN->label());
        self::assertTrue(HeroType::SWORDSMAN->rerollsOnes());
        self::assertTrue(HeroType::SWORDSMAN->continuesTurnOnSix());
        self::assertFalse(HeroType::SWORDSMAN->canRerollBattle());
        self::assertFalse(HeroType::SWORDSMAN->reincarnates());
        self::assertFalse(HeroType::SWORDSMAN->winsTies());
        self::assertFalse(HeroType::SWORDSMAN->canSneakPastMonsters());
        self::assertSame(['Combat Training', 'Unstoppable'], array_column(HeroType::SWORDSMAN->skills(), 'name'));

        foreach ([HeroType::WARRIOR, HeroType::THIEF] as $other) {
            self::assertFalse($other->rerollsOnes(), $other->value);
            self::assertFalse($other->continuesTurnOnSix(), $other->value);
        }
        self::assertSame(
            self::FLAG_KEYS,
            array_keys(HeroType::SWORDSMAN->flags()),
        );
    }

    #[Test]
    public function warlockSkillsAreDescribed(): void
    {
        self::assertSame('Lord Xanros, the Warlock', HeroType::WARLOCK->label());
        self::assertTrue(HeroType::WARLOCK->sacrificesHp());
        self::assertTrue(HeroType::WARLOCK->canMagicSwap());
        self::assertFalse(HeroType::WARLOCK->canRerollBattle());
        self::assertFalse(HeroType::WARLOCK->reincarnates());
        self::assertFalse(HeroType::WARLOCK->winsTies());
        self::assertFalse(HeroType::WARLOCK->canSneakPastMonsters());
        self::assertFalse(HeroType::WARLOCK->rerollsOnes());
        self::assertFalse(HeroType::WARLOCK->continuesTurnOnSix());
        self::assertSame(['Sacrifice', 'Magic Swap'], array_column(HeroType::WARLOCK->skills(), 'name'));

        foreach ([HeroType::WARRIOR, HeroType::THIEF, HeroType::SWORDSMAN] as $other) {
            self::assertFalse($other->sacrificesHp(), $other->value);
            self::assertFalse($other->canMagicSwap(), $other->value);
        }
        self::assertTrue(HeroType::WARLOCK->flags()['sacrificesHp']);
        self::assertTrue(HeroType::WARLOCK->flags()['canMagicSwap']);
    }

    #[Test]
    public function wizardSkillsAreDescribed(): void
    {
        self::assertSame('Argentus, the Wizard', HeroType::WIZARD->label());
        self::assertTrue(HeroType::WIZARD->keepsSpellsInCombat());
        self::assertTrue(HeroType::WIZARD->walksThroughWalls());
        self::assertFalse(HeroType::WIZARD->canRerollBattle());
        self::assertFalse(HeroType::WIZARD->reincarnates());
        self::assertFalse(HeroType::WIZARD->winsTies());
        self::assertFalse(HeroType::WIZARD->canSneakPastMonsters());
        self::assertFalse(HeroType::WIZARD->rerollsOnes());
        self::assertFalse(HeroType::WIZARD->continuesTurnOnSix());
        self::assertFalse(HeroType::WIZARD->sacrificesHp());
        self::assertFalse(HeroType::WIZARD->canMagicSwap());
        self::assertSame(['Magical Affinity', 'Astral Walking'], array_column(HeroType::WIZARD->skills(), 'name'));

        foreach ([HeroType::WARRIOR, HeroType::THIEF, HeroType::SWORDSMAN, HeroType::WARLOCK] as $other) {
            self::assertFalse($other->keepsSpellsInCombat(), $other->value);
            self::assertFalse($other->walksThroughWalls(), $other->value);
        }
        self::assertTrue(HeroType::WIZARD->flags()['keepsSpellsInCombat']);
        self::assertTrue(HeroType::WIZARD->flags()['walksThroughWalls']);
    }

    #[Test]
    public function oracleSkillsAreDescribed(): void
    {
        self::assertSame('Taia, the Oracle', HeroType::ORACLE->label());
        self::assertTrue(HeroType::ORACLE->fightsBetterOnFirstMove());
        self::assertTrue(HeroType::ORACLE->drawsTwoTokens());
        self::assertFalse(HeroType::ORACLE->canRerollBattle());
        self::assertFalse(HeroType::ORACLE->reincarnates());
        self::assertFalse(HeroType::ORACLE->winsTies());
        self::assertFalse(HeroType::ORACLE->canSneakPastMonsters());
        self::assertFalse(HeroType::ORACLE->rerollsOnes());
        self::assertFalse(HeroType::ORACLE->continuesTurnOnSix());
        self::assertFalse(HeroType::ORACLE->sacrificesHp());
        self::assertFalse(HeroType::ORACLE->canMagicSwap());
        self::assertFalse(HeroType::ORACLE->keepsSpellsInCombat());
        self::assertFalse(HeroType::ORACLE->walksThroughWalls());
        self::assertSame(['Farseeing', 'Fateweaver'], array_column(HeroType::ORACLE->skills(), 'name'));

        foreach ([HeroType::WARRIOR, HeroType::THIEF, HeroType::SWORDSMAN, HeroType::WARLOCK, HeroType::WIZARD] as $other) {
            self::assertFalse($other->fightsBetterOnFirstMove(), $other->value);
            self::assertFalse($other->drawsTwoTokens(), $other->value);
        }
        self::assertTrue(HeroType::ORACLE->flags()['fightsBetterOnFirstMove']);
        self::assertTrue(HeroType::ORACLE->flags()['drawsTwoTokens']);
        self::assertSame(
            self::FLAG_KEYS,
            array_keys(HeroType::ORACLE->flags()),
        );
    }

    /**
     * @param list<string> $ownFlags
     * @param list<string> $skillNames
     */
    #[Test]
    #[DataProvider('regentHeroes')]
    public function regentHeroSkillsAreDescribed(HeroType $hero, string $label, array $ownFlags, array $skillNames): void
    {
        self::assertSame($label, $hero->label());
        self::assertTrue($hero->isRegent());
        self::assertSame(self::FLAG_KEYS, array_keys($hero->flags()));
        foreach ($hero->flags() as $flag => $value) {
            self::assertSame(\in_array($flag, $ownFlags, true), $value, $flag);
        }
        self::assertSame($skillNames, array_column($hero->skills(), 'name'));
    }

    #[Test]
    public function baseHeroesAreNotRegent(): void
    {
        foreach ([HeroType::WARRIOR, HeroType::THIEF, HeroType::SWORDSMAN, HeroType::WARLOCK, HeroType::WIZARD, HeroType::ORACLE] as $hero) {
            self::assertFalse($hero->isRegent(), $hero->value);
            foreach (['berserk', 'choosesHealing', 'tireless', 'masterOfSword', 'lightningAttack', 'throwsDaggers', 'sprints', 'bearAttack', 'listens'] as $flag) {
                self::assertFalse($hero->flags()[$flag], $hero->value . ' ' . $flag);
            }
        }
    }

    #[Test]
    public function everySkillHasAStableUniqueKey(): void
    {
        $keys = [];
        foreach (HeroType::cases() as $hero) {
            foreach ($hero->skills() as $skill) {
                self::assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $skill['key'], $hero->value . ' ' . $skill['name']);
                $keys[] = $skill['key'];
            }
        }

        self::assertCount(20, $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'skill keys are unique across heroes');
        self::assertSame(['double_attack', 'reincarnation'], array_column(HeroType::WARRIOR->skills(), 'key'));
        self::assertSame(['bear_attack', 'listening'], array_column(HeroType::RANGER->skills(), 'key'));
    }

    #[Test]
    public function berserkBonusDependsOnHp(): void
    {
        self::assertSame([0, 1, 1, 2, 2, 0], array_map(static fn(int $hp): int => HeroType::BARBARIAN->berserkBonus($hp), [5, 4, 3, 2, 1, 0]));
        self::assertSame(0, HeroType::WARRIOR->berserkBonus(2));
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

    /**
     * A base game without random heroes, for picking a hero.
     */
    private function gameTester(bool $randomHeroes = false): MessageBusTester
    {
        $game = new class ($randomHeroes) {
            public function __construct(private readonly bool $randomHeroes) {}

            public function isRegent(): bool
            {
                return false;
            }

            public function isOnline(): bool

            {

                return false;

            }


            public function isRandomHeroes(): bool
            {
                return $this->randomHeroes;
            }
        };

        return MessageBusTester::create(static fn(GetGame $_query): object => $game);
    }
}
