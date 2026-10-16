<?php

declare(strict_types=1);

namespace App\Game\Console;

use App\Game\AI\ServerAiDriver;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Online tables with a turn time limit: every few seconds, the turns whose deadline passed are played by the AI
 * for their seat (the seat is marked away). Runs as its own service (`turnwatch` in compose).
 */
#[AsCommand(name: 'app:turn-watch', description: 'Let the AI play the overdue turns of online tables')]
final class TurnWatchCommand extends Command
{
    private const int INTERVAL_SECONDS = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly ServerAiDriver $aiDriver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * One pass over the overdue online turns.
     */
    public function tick(): int
    {
        /** @var list<string> $overdue */
        $overdue = $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT game_id FROM game.game
                WHERE online AND status IN ('started', 'turn_in_progress')
                  AND turn_deadline IS NOT NULL AND turn_deadline < now()
                ORDER BY turn_deadline
                LIMIT 20
            SQL);
        $played = 0;
        foreach ($overdue as $gameId) {
            // a long-running process: every game is read fresh, never from the last pass
            $this->entityManager->clear();
            $played += $this->aiDriver->standIn(Uuid::fromString((string) $gameId)) ? 1 : 0;
        }

        return $played;
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'One pass, then exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('turn-watch: watching the online tables');

        while (true) {
            try {
                $played = $this->tick();
                if ($played > 0) {
                    $output->writeln(sprintf('turn-watch: %d overdue turn(s) played', $played));
                }
            } catch (\Throwable $e) {
                $this->logger->error('turn.watch_failed', ['error' => $e->getMessage()]);
                $output->writeln('turn-watch: ' . $e->getMessage());
            }
            if ($input->getOption('once')) {
                break;
            }
            sleep(self::INTERVAL_SECONDS);
        }

        return Command::SUCCESS;
    }
}
