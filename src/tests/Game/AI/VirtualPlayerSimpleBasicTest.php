<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\VirtualPlayerSimple;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Telephantast\MessageBus\MessageBus;

class VirtualPlayerSimpleBasicTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        // Arrange & Act
        $messageBus = new MessageBus();
        $virtualPlayer = new VirtualPlayerSimple($messageBus);

        // Assert
        $this->assertInstanceOf(VirtualPlayerSimple::class, $virtualPlayer);
    }

    #[Test]
    public function returnsArrayFromExecuteTurn(): void
    {
        // Arrange
        $messageBus = new MessageBus();
        $virtualPlayer = new VirtualPlayerSimple($messageBus);
        
        // Use valid UUIDs
        $gameId = \App\Infrastructure\Uuid\Uuid::v7();
        $playerId = \App\Infrastructure\Uuid\Uuid::v7();

        // Act
        $result = $virtualPlayer->executeTurn($gameId, $playerId);

        // Assert
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Should have at least one error action due to missing handlers
        $hasErrorAction = false;
        foreach ($result as $action) {
            if ($action['type'] === 'ai_error') {
                $hasErrorAction = true;
                break;
            }
        }
        $this->assertTrue($hasErrorAction, 'Should have error action when handlers are missing');
    }
}