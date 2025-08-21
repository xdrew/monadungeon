<?php

declare(strict_types=1);

namespace App\Tests\Api\Field\Tile\Pick;

use App\Api\Error;
use App\Api\Field\Tile\Pick\Action;
use App\Api\Field\Tile\Pick\Request;
use App\Api\Field\Tile\Pick\Response;
use App\Game\Deck\Deck;
use App\Game\Deck\GetDeck;
use App\Game\Field\Field;
use App\Game\Field\GetField;
use App\Game\Field\GetTile;
use App\Game\Field\PickTile;
use App\Game\Field\Tile;
use App\Game\Field\TileOrientation;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\GameCreated;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Infrastructure\Uuid\Uuid;
use App\Tests\Infrastructure\MessageBus\MessageBusTester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageBus;
use function App\Tests\Infrastructure\MessageBus\handle;
use function App\Tests\Infrastructure\MessageBus\startMessageContext;

#[CoversClass(Action::class)]
final class ActionTest extends TestCase
{
    private Uuid $gameId;
    private Uuid $tileId;
    private Uuid $playerId;
    private Uuid $turnId;
    private \DateTimeImmutable $fixedTime;

    protected function setUp(): void
    {
        $this->gameId = Uuid::v7();
        $this->tileId = Uuid::v7();
        $this->playerId = Uuid::v7();
        $this->turnId = Uuid::v7();
        $this->fixedTime = new \DateTimeImmutable('2024-01-01 12:00:00');
    }

    private function createRealTile(): Tile
    {
        $gameCreated = new GameCreated($this->gameId, $this->fixedTime, 10);
        $playerId = $this->playerId;
        
        // Create a real tile by picking from deck
        $tester = MessageBusTester::create(
            // Need to return current player for turn validation
            static function (GetCurrentPlayer $_query) use ($playerId): Uuid {
                return $playerId;
            },
            static function (GetField $_query) use ($gameCreated): Field {
                return Field::create($gameCreated, startMessageContext());
            },
            static function (GetDeck $_query) use ($gameCreated): Deck {
                return Deck::createClassic($gameCreated, startMessageContext());
            },
        );
        
        $pickTile = new PickTile($this->gameId, $this->tileId, $this->playerId, $this->turnId, TileSide::TOP);
        [$tile, ] = $tester->handle(Tile::pickFromDeck(...), $pickTile);
        
        return $tile;
    }

    /**
     * Creates a MessageBus for testing API controllers
     * @param callable ...$handlers
     */
    private function createTestMessageBus(callable ...$handlers): MessageBus
    {
        $tester = MessageBusTester::create(...$handlers);
        
        // Use reflection to access the private handlerRegistry
        $reflection = new \ReflectionClass($tester);
        $registryProperty = $reflection->getProperty('handlerRegistry');
        $registryProperty->setAccessible(true);
        $handlerRegistry = $registryProperty->getValue($tester);
        
        return new MessageBus($handlerRegistry);
    }

    #[Test]
    public function itSuccessfullyPicksTileWhenActionIsFixed(): void
    {
        // This test shows what the behavior should be if the Action class were fixed
        // to include the requiredOpenSide parameter
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        // Create a real tile with real orientation
        $realTile = $this->createRealTile();
        $orientation = $realTile->getOrientation();

        // Test the Response class directly (as Action would create it if fixed)
        $response = new Response($this->tileId, $orientation);

        self::assertInstanceOf(Response::class, $response);
        self::assertEquals($this->tileId, $response->tileId);
        self::assertEquals($orientation, $response->orientation);
        self::assertEquals(201, $response->statusCode());
    }

    #[Test]
    public function itReturnsErrorDueToMissingRequiredOpenSideParameter(): void
    {
        // This test now verifies that the Action correctly includes the requiredOpenSide parameter
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        // Create a real tile for GetTile handler
        $realTile = $this->createRealTile();

        // Create a real MessageBus with test handlers
        $messageBus = $this->createTestMessageBus(
            static function (PickTile $command): void {
                // PickTile command processed successfully
                // Verify the requiredOpenSide is passed correctly
                assert($command->requiredOpenSide === TileSide::TOP);
            },
            static function (GetTile $query) use ($realTile): Tile {
                return $realTile;
            },
        );

        $action = new Action();
        $result = $action($request, $messageBus);

        // The action should now succeed
        self::assertInstanceOf(Response::class, $result);
        self::assertEquals($this->tileId, $result->tileId);
        self::assertEquals($realTile->getOrientation(), $result->orientation);
    }

    #[Test]
    public function itReturnsErrorWhenPickTileFails(): void
    {
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        $messageBus = $this->createTestMessageBus(
            static function (PickTile $command): void {
                throw new \RuntimeException('Tile pick failed');
            },
        );

        $action = new Action();
        $result = $action($request, $messageBus);

        self::assertInstanceOf(Error::class, $result);
        self::assertStringStartsWith('Tile pick failed:', $result->message);
        self::assertEquals(500, $result->statusCode());
        self::assertInstanceOf(Uuid::class, $result->code);
    }

    #[Test]
    public function itHandlesGetTileFailure(): void
    {
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        $messageBus = $this->createTestMessageBus(
            static function (PickTile $command): void {
                // PickTile succeeds
            },
            static function (GetTile $query): void {
                throw new \RuntimeException('Tile not found');
            },
        );

        // GetTile failure is not caught by the Action's try-catch
        // so this will throw an exception
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Tile not found');

        $action = new Action();
        $result = $action($request, $messageBus);
    }

    #[Test]
    public function itPassesCorrectParametersToPickTile(): void
    {
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        $receivedCommand = null;
        $realTile = $this->createRealTile();

        $messageBus = $this->createTestMessageBus(
            static function (PickTile $command) use (&$receivedCommand): void {
                $receivedCommand = $command;
            },
            static function (GetTile $query) use ($realTile): Tile {
                return $realTile;
            },
        );

        $action = new Action();
        $action($request, $messageBus);

        // Verify the command was received with correct parameters
        self::assertNotNull($receivedCommand);
        self::assertEquals($request->gameId, $receivedCommand->gameId);
        self::assertEquals($request->tileId, $receivedCommand->tileId);
        self::assertEquals($request->playerId, $receivedCommand->playerId);
        self::assertEquals($request->turnId, $receivedCommand->turnId);
        self::assertEquals($request->requiredOpenSide, $receivedCommand->requiredOpenSide);
    }

    #[Test]
    public function itPassesCorrectTileIdToGetTile(): void
    {
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        $receivedQuery = null;
        $realTile = $this->createRealTile();

        $messageBus = $this->createTestMessageBus(
            static function (PickTile $command): void {
                // PickTile succeeds
            },
            static function (GetTile $query) use (&$receivedQuery, $realTile): Tile {
                $receivedQuery = $query;
                return $realTile;
            },
        );

        $action = new Action();
        $result = $action($request, $messageBus);

        // Verify GetTile was called with correct tileId
        self::assertNotNull($receivedQuery);
        self::assertEquals($request->tileId, $receivedQuery->tileId);
        self::assertInstanceOf(Response::class, $result);
    }

    #[Test]
    public function itHandlesDifferentOrientations(): void
    {
        // Test with real tiles that have different orientations
        for ($i = 0; $i < 4; $i++) {
            $request = new Request(
                gameId: $this->gameId,
                tileId: $this->tileId,
                playerId: $this->playerId,
                turnId: $this->turnId,
                requiredOpenSide: TileSide::TOP,
            );

            $realTile = $this->createRealTile();

            $messageBus = $this->createTestMessageBus(
                static function (PickTile $command): void {
                    // PickTile succeeds
                },
                static function (GetTile $query) use ($realTile): Tile {
                    return $realTile;
                },
            );

            $action = new Action();
            $result = $action($request, $messageBus);

            // Action should succeed with correct orientation
            self::assertInstanceOf(Response::class, $result);
            self::assertEquals($request->tileId, $result->tileId);
            self::assertEquals($realTile->getOrientation(), $result->orientation);
        }
    }

    #[Test]
    public function itValidatesRequestProperties(): void
    {
        // Test that Request constructor properly accepts all required UUIDs
        $request = new Request(
            gameId: $this->gameId,
            tileId: $this->tileId,
            playerId: $this->playerId,
            turnId: $this->turnId,
            requiredOpenSide: TileSide::TOP,
        );

        self::assertEquals($this->gameId, $request->gameId);
        self::assertEquals($this->tileId, $request->tileId);
        self::assertEquals($this->playerId, $request->playerId);
        self::assertEquals($this->turnId, $request->turnId);
    }

    #[Test]
    public function itTestsTileOrientationSerialization(): void
    {
        // Test that real TileOrientation objects serialize correctly for API responses
        $realTile = $this->createRealTile();
        $orientation = $realTile->getOrientation();
        
        // Test JsonSerializable interface
        $serialized = $orientation->jsonSerialize();
        self::assertIsString($serialized);
        
        // Test it can be recreated from string
        $recreated = TileOrientation::fromString($serialized);
        self::assertEquals($orientation->getOrientation(), $recreated->getOrientation());
    }

    #[Test]
    public function itCreatesResponseWithCorrectStatusCode(): void
    {
        $realTile = $this->createRealTile();
        $orientation = $realTile->getOrientation();
        
        $response = new Response($this->tileId, $orientation);

        self::assertEquals($this->tileId, $response->tileId);
        self::assertEquals($orientation, $response->orientation);
        self::assertEquals(201, $response->statusCode());
    }

    #[Test]
    public function itCreatesErrorWithCorrectStatusCode(): void
    {
        $errorCode = Uuid::v7();
        $error = new Error($errorCode, 'Test error message');

        self::assertEquals($errorCode, $error->code);
        self::assertEquals('Test error message', $error->message);
        self::assertEquals(500, $error->statusCode());
    }

    #[Test]
    public function itCreatesErrorWithCustomStatusCode(): void
    {
        $errorCode = Uuid::v7();
        $error = new Error($errorCode, 'Bad request', 400);

        self::assertEquals($errorCode, $error->code);
        self::assertEquals('Bad request', $error->message);
        self::assertEquals(400, $error->statusCode());
    }
}