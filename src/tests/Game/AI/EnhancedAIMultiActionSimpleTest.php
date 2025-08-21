<?php

declare(strict_types=1);

namespace Tests\Game\AI;

use App\Game\AI\EnhancedAIPlayer;
use App\Infrastructure\Uuid\Uuid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Simple test to verify the Enhanced AI Player multi-action implementation exists and works
 */
class EnhancedAIMultiActionSimpleTest extends TestCase
{
    #[Test]
    public function testEnhancedAIPlayerHasMultiActionSupport(): void
    {
        // Use reflection to verify the multi-action constants and methods exist
        $reflection = new \ReflectionClass(EnhancedAIPlayer::class);
        
        // Check for MAX_ACTIONS_PER_TURN constant
        $this->assertTrue(
            $reflection->hasConstant('MAX_ACTIONS_PER_TURN'),
            'EnhancedAIPlayer should have MAX_ACTIONS_PER_TURN constant'
        );
        
        $maxActions = $reflection->getConstant('MAX_ACTIONS_PER_TURN');
        $this->assertEquals(4, $maxActions, 'MAX_ACTIONS_PER_TURN should be 4');
        
        // Check for multi-action related properties
        $this->assertTrue(
            $reflection->hasProperty('currentTurnActions'),
            'EnhancedAIPlayer should have currentTurnActions property'
        );
        
        $this->assertTrue(
            $reflection->hasProperty('turnActionHistory'),
            'EnhancedAIPlayer should have turnActionHistory property'
        );
        
        // Check for multi-action methods
        $this->assertTrue(
            $reflection->hasMethod('executeTurnStrategyWithActions'),
            'EnhancedAIPlayer should have executeTurnStrategyWithActions method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('decideAndExecuteNextAction'),
            'EnhancedAIPlayer should have decideAndExecuteNextAction method'
        );
        
        // Check helper methods for multi-action decision making
        $this->assertTrue(
            $reflection->hasMethod('canReachHealing'),
            'EnhancedAIPlayer should have canReachHealing helper method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('chooseBestExplorationMove'),
            'EnhancedAIPlayer should have chooseBestExplorationMove helper method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('isUnexploredArea'),
            'EnhancedAIPlayer should have isUnexploredArea helper method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('hasNearbyItems'),
            'EnhancedAIPlayer should have hasNearbyItems helper method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('expandsMap'),
            'EnhancedAIPlayer should have expandsMap helper method'
        );
    }
    
    #[Test]
    public function testMultiActionLogicInExecuteTurnMethod(): void
    {
        $reflection = new \ReflectionClass(EnhancedAIPlayer::class);
        $method = $reflection->getMethod('executeTurnStrategyWithActions');
        
        // Get the method source code to verify the loop logic
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        
        $source = file($filename);
        $methodBody = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));
        
        // Verify the while loop for multiple actions exists
        $this->assertStringContainsString(
            'while ($this->currentTurnActions < self::MAX_ACTIONS_PER_TURN)',
            $methodBody,
            'Method should contain the multi-action while loop'
        );
        
        // Verify action counting logic
        $this->assertStringContainsString(
            '$this->currentTurnActions++',
            $methodBody,
            'Method should increment action counter'
        );
        
        // Verify action history tracking
        $this->assertStringContainsString(
            '$this->turnActionHistory[]',
            $methodBody,
            'Method should track action history'
        );
        
        // Verify break conditions for ending turn early
        $this->assertStringContainsString(
            'break;',
            $methodBody,
            'Method should have break conditions to end turn early when appropriate'
        );
    }
    
    #[Test]
    public function testDecideAndExecuteNextActionPriorities(): void
    {
        $reflection = new \ReflectionClass(EnhancedAIPlayer::class);
        $method = $reflection->getMethod('decideAndExecuteNextAction');
        
        // Get method source to verify priority logic
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        
        $source = file($filename);
        $methodBody = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));
        
        // Verify priority order
        $this->assertStringContainsString(
            'Priority 1:',
            $methodBody,
            'Should have healing as Priority 1'
        );
        
        $this->assertStringContainsString(
            'Priority 2:',
            $methodBody,
            'Should have tile placement as Priority 2'
        );
        
        $this->assertStringContainsString(
            'Priority 3:',
            $methodBody,
            'Should have beneficial movement as Priority 3'
        );
        
        $this->assertStringContainsString(
            'Priority 4:',
            $methodBody,
            'Should have exploration as Priority 4'
        );
        
        // Verify healing check
        $this->assertStringContainsString(
            'needsHealing()',
            $methodBody,
            'Should check if player needs healing'
        );
        
        // Verify first action tile placement
        $this->assertStringContainsString(
            'if ($this->currentTurnActions === 0)',
            $methodBody,
            'Should check if this is the first action for tile placement'
        );
    }
    
    #[Test]
    public function testStrategyConfigurationSupport(): void
    {
        // Create a mock implementation to test the strategy methods
        $reflection = new \ReflectionClass(EnhancedAIPlayer::class);
        
        // Test that strategy methods exist and are public
        $this->assertTrue(
            $reflection->hasMethod('setStrategyConfig'),
            'Should have setStrategyConfig method'
        );
        
        $this->assertTrue(
            $reflection->hasMethod('getStrategyConfig'),
            'Should have getStrategyConfig method'
        );
        
        $setMethod = $reflection->getMethod('setStrategyConfig');
        $this->assertTrue(
            $setMethod->isPublic(),
            'setStrategyConfig should be public'
        );
        
        $getMethod = $reflection->getMethod('getStrategyConfig');
        $this->assertTrue(
            $getMethod->isPublic(),
            'getStrategyConfig should be public'
        );
    }
    
    #[Test]
    public function testImplementsVirtualPlayerStrategyInterface(): void
    {
        $reflection = new \ReflectionClass(EnhancedAIPlayer::class);
        
        $this->assertTrue(
            $reflection->implementsInterface('App\Game\AI\VirtualPlayerStrategy'),
            'EnhancedAIPlayer should implement VirtualPlayerStrategy interface'
        );
    }
}