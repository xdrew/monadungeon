<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Deck\Deck;
use App\Game\Deck\DeckCreated;
use App\Game\GameLifecycle\GameCreated;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageContext;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

#[CoversClass(Deck::class)]
final class DeckTest extends TestCase
{
    #[Test]
    public function itCreatesClassicDeck(): void
    {
        $gameId = Uuid::v7();
        $gameCreated = new GameCreated($gameId, new \DateTimeImmutable(), 88);
        
        $tester = MessageBusTester::create();
        $messageContext = startMessageContext();
        
        [$deck, $messages] = $tester->handle(
            function (GameCreated $event, MessageContext $context) {
                return Deck::createClassic($event, $context);
            },
            $gameCreated
        );
        
        self::assertInstanceOf(Deck::class, $deck);
        self::assertCount(1, $messages);
        self::assertInstanceOf(DeckCreated::class, $messages[0]);
    }
} 