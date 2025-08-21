<?php

declare(strict_types=1);

namespace App\Game\Console;

use App\Game\GameLifecycle\CreateGame;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Telephantast\MessageBus\MessageBus;

#[AsCommand(
    name: 'game:test-logging',
    description: 'Test game logging functionality',
)]
final class TestGameLoggingCommand extends Command
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create a test game ID
        $gameId = '123e4567-e89b-12d3-a456-426614174000';

        $io->title('Testing Game Logging');
        $io->text([
            "Game ID: {$gameId}",
            "Log file: var/log/games/{$gameId}.log",
        ]);

        // Create and dispatch command
        $createGameCommand = new CreateGame(
            Uuid::fromString($gameId),
        );

        try {
            $io->section('Dispatching CreateGame command...');
            $result = $this->messageBus->dispatch($createGameCommand);
            $io->success('Command dispatched successfully!');
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // Check if log file was created
        $logFile = $this->projectDir . "/var/log/games/{$gameId}.log";

        if (file_exists($logFile)) {
            $io->success('Log file created successfully!');

            $io->section('Log file contents (first 1000 characters):');
            $content = file_get_contents($logFile);
            $io->text(substr($content, 0, 1000));

            if (\strlen($content) > 1000) {
                $io->text('... (truncated)');
            }

            $io->newLine();
            $io->info("Full log file: {$logFile}");
        } else {
            $io->warning("Log file not found at: {$logFile}");
        }

        return Command::SUCCESS;
    }
}
