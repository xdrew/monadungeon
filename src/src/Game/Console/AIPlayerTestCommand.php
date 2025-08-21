<?php

declare(strict_types=1);

namespace App\Game\Console;

use App\Game\AI\AIPlayerManager;
use App\Game\AI\EnhancedAIPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\StartGame;
use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Telephantast\MessageBus\MessageBus;

#[AsCommand(
    name: 'game:ai:test',
    description: 'Test the Enhanced AI Player in a game',
)]
final class AIPlayerTestCommand extends Command
{
    public function __construct(
        private readonly AIPlayerManager $aiPlayerManager,
        private readonly EnhancedAIPlayer $enhancedAIPlayer,
        private readonly MessageBus $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('players', 'p', InputOption::VALUE_REQUIRED, 'Number of AI players (1-4)', '2')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED, 'AI strategy (aggressive, defensive, balanced, treasure_hunter)', 'balanced')
            ->addOption('max-turns', 'm', InputOption::VALUE_REQUIRED, 'Maximum number of turns', '50')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between turns in milliseconds', '500')
            ->addOption('verbose-ai', null, InputOption::VALUE_NONE, 'Show detailed AI decision logs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $numPlayers = (int) $input->getOption('players');
        $strategy = $input->getOption('strategy');
        $maxTurns = (int) $input->getOption('max-turns');
        $delay = (int) $input->getOption('delay');
        $verboseAI = $input->getOption('verbose-ai');

        $io->title('🤖 Testing Enhanced AI Player');
        
        // Create a new game
        $gameId = Uuid::v7();
        $io->section('Creating Game');
        $io->text("Game ID: {$gameId->toString()}");
        
        try {
            // Create game
            $this->messageBus->dispatch(new CreateGame($gameId));
            $io->success('Game created');

            // Add AI players
            $io->section('Adding AI Players');
            $playerIds = [];
            
            for ($i = 1; $i <= $numPlayers; $i++) {
                $playerId = Uuid::v7();
                $playerIds[] = $playerId;
                
                // Add player to game
                $this->messageBus->dispatch(new AddPlayer($gameId, $playerId));
                
                // Register as AI player
                $playerStrategy = $i === 1 ? $strategy : 'balanced'; // First player uses specified strategy
                $this->aiPlayerManager->registerAIPlayer($gameId, $playerId, $playerStrategy);
                
                $io->text("Player {$i}: {$playerId->toString()} (Strategy: {$playerStrategy})");
            }
            
            $io->success("{$numPlayers} AI players added");

            // Start game
            $io->section('Starting Game');
            $this->messageBus->dispatch(new StartGame($gameId));
            $io->success('Game started');

            // Run game with AI players
            $io->section('Running Game with AI Players');
            $io->progressStart($maxTurns);
            
            $turnCount = 0;
            $gameEnded = false;
            
            while ($turnCount < $maxTurns && !$gameEnded) {
                // Get current game state
                $game = $this->messageBus->dispatch(new GetGame($gameId));
                
                if ($game->status === 'ended' || $game->status === 'finished') {
                    $gameEnded = true;
                    break;
                }

                // Get current turn
                $currentTurn = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
                
                if ($currentTurn) {
                    $currentPlayerId = $currentTurn->playerId;
                    $playerNumber = array_search($currentPlayerId, $playerIds) + 1;
                    
                    if ($verboseAI) {
                        $io->newLine();
                        $io->text("Turn {($turnCount + 1)}: Player {$playerNumber}'s turn");
                    }
                    
                    // Execute AI turn
                    $success = $this->aiPlayerManager->executeAITurnIfNeeded($gameId);
                    
                    if ($success) {
                        $turnCount++;
                        $io->progressAdvance();
                        
                        if ($verboseAI) {
                            // Show player state after turn
                            $player = $this->messageBus->dispatch(new GetPlayer($gameId, $currentPlayerId));
                            $io->text("  HP: {$player->hp}/{$player->maxHp}");
                            $io->text("  Weapons: " . count($player->inventory['weapons']));
                            $io->text("  Keys: " . count($player->inventory['keys']));
                            $io->text("  Treasures: " . count($player->inventory['treasures']));
                        }
                    }
                }
                
                // Delay between turns
                usleep($delay * 1000);
            }
            
            $io->progressFinish();

            // Show final results
            $io->section('Game Results');
            
            if ($gameEnded) {
                $io->success('Game ended!');
                
                // Show final scores
                $io->table(
                    ['Player', 'HP', 'Weapons', 'Keys', 'Treasures', 'Status'],
                    $this->getPlayerStats($gameId, $playerIds)
                );
                
                // Determine winner
                $winner = $this->determineWinner($gameId, $playerIds);
                if ($winner) {
                    $winnerNumber = array_search($winner, $playerIds) + 1;
                    $io->success("🏆 Player {$winnerNumber} wins!");
                }
            } else {
                $io->warning("Game did not end within {$maxTurns} turns");
            }

            // Show AI statistics
            $io->section('AI Statistics');
            foreach ($playerIds as $index => $playerId) {
                $stats = $this->aiPlayerManager->getAIPlayerStats($gameId, $playerId);
                if (!empty($stats)) {
                    $io->text("Player " . ($index + 1) . ":");
                    $io->text("  Strategy: {$stats['strategyType']}");
                    $io->text("  Turns played: {$stats['turnCount']}");
                }
            }

            // Clean up
            $this->aiPlayerManager->clearGameAIPlayers($gameId);
            
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Error during AI test: ' . $e->getMessage());
            
            if ($verboseAI) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Get player statistics for display
     */
    private function getPlayerStats(Uuid $gameId, array $playerIds): array
    {
        $stats = [];
        
        foreach ($playerIds as $index => $playerId) {
            try {
                $player = $this->messageBus->dispatch(new GetPlayer($gameId, $playerId));
                
                $stats[] = [
                    'Player ' . ($index + 1),
                    "{$player->hp}/{$player->maxHp}",
                    count($player->inventory['weapons'] ?? []),
                    count($player->inventory['keys'] ?? []),
                    count($player->inventory['treasures'] ?? []),
                    $player->hp > 0 ? '✅ Active' : '❌ Defeated',
                ];
            } catch (\Throwable $e) {
                $stats[] = [
                    'Player ' . ($index + 1),
                    '?',
                    '?',
                    '?',
                    '?',
                    '❓ Unknown',
                ];
            }
        }
        
        return $stats;
    }

    /**
     * Determine the winner based on treasure points
     */
    private function determineWinner(Uuid $gameId, array $playerIds): ?Uuid
    {
        $maxTreasureValue = 0;
        $winner = null;
        
        foreach ($playerIds as $playerId) {
            try {
                $player = $this->messageBus->dispatch(new GetPlayer($gameId, $playerId));
                
                $treasureValue = 0;
                foreach ($player->inventory['treasures'] ?? [] as $treasure) {
                    $treasureValue += $treasure['treasureValue'] ?? 0;
                }
                
                if ($treasureValue > $maxTreasureValue) {
                    $maxTreasureValue = $treasureValue;
                    $winner = $playerId;
                }
            } catch (\Throwable $e) {
                // Player might not exist or be invalid
            }
        }
        
        return $winner;
    }
}