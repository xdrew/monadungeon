<?php

declare(strict_types=1);

namespace App\Tests\Game\Player;

use App\Game\GameLifecycle\GetGame;
use App\Game\GameLifecycle\PlayerAdded;
use App\Game\Item\Item;
use App\Game\Item\ItemCategory;
use App\Game\Item\ItemName;
use App\Game\Item\ItemType;
use App\Game\Player\AddItemToInventory;
use App\Game\Player\Error\InventoryFullException;
use App\Game\Player\HeroType;
use App\Game\Player\PickHero;
use App\Game\Player\Player;
use App\Game\Player\PlayerCursed;
use App\Game\Player\ReplaceInventoryItem;
use App\Game\Player\ResetPlayerHP;
use App\Game\Player\SetHealLimit;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function App\Tests\Infrastructure\MessageBus\handle;

/**
 * Karak: Regent heroes on the Player aggregate: the expansion gate, Valduk's heal limit,
 * Baron Markul's Sword Master and Hanna's Dagger Throwing.
 */
#[CoversClass(Player::class)]
final class RegentHeroPlayerTest extends TestCase
{
    private Uuid $gameId;

    private Uuid $playerId;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->playerId = Uuid::v7();
    }

    #[Test]
    public function aRegentHeroCanOnlyBePickedInARegentGame(): void
    {
        $player = $this->createPlayer(null, 5);

        try {
            $this->tester(regent: false)->handle($player->pickHero(...), new PickHero($this->gameId, $this->playerId, HeroType::RANGER));
            self::fail('A Regent hero needs the expansion');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Karak: Regent', $e->getMessage());
        }
        self::assertNull($player->getChosenHero());

        $this->tester(regent: false)->handle($player->pickHero(...), new PickHero($this->gameId, $this->playerId, HeroType::WARRIOR));
        self::assertSame(HeroType::WARRIOR, $player->getChosenHero(), 'Base heroes are always available');

        $this->tester(regent: true)->handle($player->pickHero(...), new PickHero($this->gameId, $this->playerId, HeroType::RANGER));
        self::assertSame(HeroType::RANGER, $player->getChosenHero());
    }

    #[Test]
    public function valdukHealsOnlyUpToHisLimit(): void
    {
        $player = $this->createPlayer(HeroType::BARBARIAN, 1);
        $player->setHealLimit(new SetHealLimit($this->gameId, $this->playerId, 3));
        self::assertSame(3, $player->getHealLimit());

        $player->resetHP(new ResetPlayerHP($this->playerId, $this->gameId));
        self::assertSame(3, $player->getHP());

        $player->setTestHp(4);
        $player->resetHP(new ResetPlayerHP($this->playerId, $this->gameId));
        self::assertSame(4, $player->getHP(), 'Healing never takes HP away');

        $player->setTestHp(0);
        $player->resetHP(new ResetPlayerHP($this->playerId, $this->gameId, afterStun: true));
        self::assertSame(Player::HP_AFTER_STUN, $player->getHP(), 'Waking up from a stun is not a heal');

        $player->setHealLimit(new SetHealLimit($this->gameId, $this->playerId, null));
        $player->resetHP(new ResetPlayerHP($this->playerId, $this->gameId));
        self::assertSame(Player::MAX_HP, $player->getHP());
    }

    #[Test]
    public function aCursedValdukHealsFully(): void
    {
        $player = $this->createPlayer(HeroType::BARBARIAN, 1);
        $player->setHealLimit(new SetHealLimit($this->gameId, $this->playerId, 2));
        $player->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));

        $player->resetHP(new ResetPlayerHP($this->playerId, $this->gameId));

        self::assertSame(Player::MAX_HP, $player->getHP());
    }

    #[Test]
    public function onlyValdukSetsAHealLimitAndItMustBeAValidHp(): void
    {
        $other = $this->createPlayer(HeroType::WARRIOR, 5);

        try {
            $other->setHealLimit(new SetHealLimit($this->gameId, $this->playerId, 3));
            self::fail('Only Valduk chooses');
        } catch (\DomainException) {
        }

        $valduk = $this->createPlayer(HeroType::BARBARIAN, 5);
        foreach ([0, 6] as $hp) {
            try {
                $valduk->setHealLimit(new SetHealLimit($this->gameId, $this->playerId, $hp));
                self::fail("{$hp} HP is not a valid limit");
            } catch (\DomainException) {
            }
        }
        self::assertNull($valduk->getHealLimit());
    }

    #[Test]
    public function aSwordHasStrengthThreeForMarkul(): void
    {
        $markul = $this->createPlayer(HeroType::BATTLE_MAGE, 5);
        $this->setInventory($markul, weapons: [$this->sword(), $this->dagger()]);
        self::assertSame(4, $markul->calculateWeaponDamage());
        self::assertSame(3, $markul->itemStrength($this->sword()));

        $other = $this->createPlayer(HeroType::WARRIOR, 5);
        $this->setInventory($other, weapons: [$this->sword(), $this->dagger()]);
        self::assertSame(3, $other->calculateWeaponDamage());

        $markul->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));
        self::assertSame(3, $markul->calculateWeaponDamage(), 'No Sword Master while cursed');
    }

    #[Test]
    public function hannaPutsADaggerIntoASpellSlotOnceTheWeaponSlotsAreFull(): void
    {
        $hanna = $this->createPlayer(HeroType::ACROBAT, 5);
        $this->setInventory($hanna, weapons: [$this->sword(), $this->dagger()]);

        $dagger = $this->dagger();
        $this->tester()->handle($hanna->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $dagger));

        $inventory = $hanna->getInventory();
        self::assertCount(2, $inventory[ItemCategory::WEAPON->value]);
        self::assertCount(1, $inventory[ItemCategory::SPELL->value]);
        self::assertSame(ItemType::DAGGER, Item::fromAnything($inventory[ItemCategory::SPELL->value][0])->type);
        self::assertSame(4, $hanna->calculateWeaponDamage(), 'sword 2 + dagger 1 + thrown dagger 1');
        self::assertCount(3, $hanna->getWeaponItems());
        self::assertSame([], $hanna->getConsumableItems(), 'A dagger is never offered as a consumable');
    }

    #[Test]
    public function aSwordStillNeedsAWeaponSlot(): void
    {
        $hanna = $this->createPlayer(HeroType::ACROBAT, 5);
        $this->setInventory($hanna, weapons: [$this->sword(), $this->dagger()]);

        $this->expectException(InventoryFullException::class);
        $this->tester()->handle($hanna->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $this->sword()));
    }

    #[Test]
    public function withEverySlotFullADaggerMayReplaceAWeaponOrASpell(): void
    {
        $hanna = $this->createPlayer(HeroType::ACROBAT, 5);
        $fireball = $this->fireball();
        $this->setInventory($hanna, weapons: [$this->sword(), $this->dagger()], spells: [$fireball, $this->fireball(), $this->fireball()]);

        try {
            $this->tester()->handle($hanna->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $this->dagger()));
            self::fail('Every slot is taken');
        } catch (InventoryFullException $e) {
            self::assertCount(5, $e->currentInventory, 'Weapons and spells are offered for replacement');
        }

        $dagger = $this->dagger();
        $this->tester()->handle($hanna->replaceInventoryItem(...), new ReplaceInventoryItem($this->playerId, $this->gameId, $fireball->itemId, $dagger));

        $spells = array_map(static fn(Item|array $item): ItemType => Item::fromAnything($item)->type, $hanna->getInventory()[ItemCategory::SPELL->value]);
        self::assertContains(ItemType::DAGGER, $spells, 'The dagger takes the replaced spell slot');
        self::assertCount(2, $hanna->getInventory()[ItemCategory::WEAPON->value]);
        self::assertCount(2, $hanna->getConsumableItems());
    }

    #[Test]
    public function aCursedHannaCannotThrowDaggers(): void
    {
        $hanna = $this->createPlayer(HeroType::ACROBAT, 5);
        $this->setInventory($hanna, weapons: [$this->sword(), $this->dagger()], spells: [$this->dagger()]);
        $hanna->onPlayerCursed(new PlayerCursed($this->gameId, $this->playerId, Uuid::v7()));

        self::assertSame(3, $hanna->calculateWeaponDamage(), 'The thrown dagger stays but adds nothing');
        self::assertCount(2, $hanna->getWeaponItems());
        self::assertFalse($hanna->hasRoomFor(ItemType::DAGGER));

        $this->expectException(InventoryFullException::class);
        $this->tester()->handle($hanna->addItem(...), new AddItemToInventory($this->gameId, $this->playerId, $this->dagger()));
    }

    #[Test]
    public function hannaHasRoomForADaggerWhileASpellSlotIsFree(): void
    {
        $hanna = $this->createPlayer(HeroType::ACROBAT, 5);
        $this->setInventory($hanna, weapons: [$this->sword(), $this->dagger()], spells: [$this->fireball(), $this->fireball()]);
        self::assertTrue($hanna->hasRoomFor(ItemType::DAGGER));
        self::assertFalse($hanna->hasRoomFor(ItemType::SWORD));

        $other = $this->createPlayer(HeroType::THIEF, 5);
        $this->setInventory($other, weapons: [$this->sword(), $this->dagger()]);
        self::assertFalse($other->hasRoomFor(ItemType::DAGGER));
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
     * @param list<Item> $weapons
     * @param list<Item> $spells
     */
    private function setInventory(Player $player, array $weapons = [], array $spells = []): void
    {
        (new \ReflectionProperty(Player::class, 'inventory'))->setValue($player, [
            ItemCategory::KEY->value => [],
            ItemCategory::WEAPON->value => $weapons,
            ItemCategory::SPELL->value => $spells,
            ItemCategory::TREASURE->value => [],
        ]);
    }

    private function tester(bool $regent = true): MessageBusTester
    {
        $game = new class ($regent) {
            public function __construct(private readonly bool $regent) {}

            public function isRegent(): bool
            {
                return $this->regent;
            }

            public function getStatus(): object
            {
                return new class () {
                    public function isFinished(): bool
                    {
                        return false;
                    }
                };
            }
        };

        return MessageBusTester::create(static fn(GetGame $_query): object => $game);
    }

    private function dagger(): Item
    {
        return new Item(ItemName::GIANT_RAT, ItemType::DAGGER, 5, 0, true);
    }

    private function sword(): Item
    {
        return new Item(ItemName::SKELETON_WARRIOR, ItemType::SWORD, 9, 0, true);
    }

    private function fireball(): Item
    {
        return new Item(ItemName::MUMMY, ItemType::FIREBALL, 7, 0, true);
    }
}
