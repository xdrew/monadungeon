<?php

declare(strict_types=1);

namespace App\Game\Console;

use App\Game\Field\Field;
use App\Game\Field\GetField;
use App\Game\Field\GetTile;
use App\Game\Field\PickTile;
use App\Game\Field\PlaceTile;
use App\Game\Field\RotateTile;
use App\Game\Field\Tile;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\Game;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\StartGame;
use App\Game\Player\GetReady;
use App\Game\Player\PickCharacter;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * @psalm-suppress ForbiddenCode, UnusedVariable
 */
#[AsCommand('game:run-turns')]
final class GameRunTurnBasedConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('size', InputArgument::OPTIONAL, 'Deck size', default: 100)
            ->addOption('players', 'p', InputOption::VALUE_OPTIONAL, 'Number of players', default: 4);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->getApplication()?->doRun(
            new ArrayInput([
                'command' => 'dbal:run-sql',
                'sql' => <<<'CMD'
                        do
                        $$ 
                        declare _table text;
                        begin
                            for _table in
                                select inhrelid::regclass::text
                                from pg_catalog.pg_class
                                inner join pg_catalog.pg_inherits on (inhrelid = pg_class.oid)
                                where relkind = 'r'
                                  and relnamespace::regnamespace not in ('pg_catalog', 'information_schema')
                            loop
                                execute 'drop table ' || _table;
                            end loop;
                            for _table in
                                select oid::regclass::text
                                from pg_catalog.pg_class
                                where relkind = 'r'
                                  and relnamespace::regnamespace not in ('pg_catalog', 'information_schema')
                                  and not (relnamespace::regnamespace::text = 'public' and relname = 'doctrine_migration_versions')
                            loop
                                execute 'truncate table ' || _table || ' cascade';
                            end loop;
                        end
                        $$;
                    CMD,
            ]),
            $output,
        );

        $deckSize = (int) $input->getArgument('size');
        $numPlayers = (int) $input->getOption('players');
        $gameId = Uuid::fromString('0c9a14d1-fb5e-4ac4-8ab8-1ff1d27fd3e9');

        // Create player IDs
        $playerIds = [];
        $characterIds = [];
        for ($i = 0; $i < $numPlayers; ++$i) {
            $playerIds[] = Uuid::v7();
            $characterIds[] = Uuid::v7();
        }

        // Create the game
        $output->writeln("Creating game with ID: {$gameId}");
        $this->messageBus->dispatch(new CreateGame($gameId, deckSize: $deckSize));

        // Add players to the game
        foreach ($playerIds as $index => $playerId) {
            $output->writeln("Adding player {$index} with ID: {$playerId}");
            $this->messageBus->dispatch(new AddPlayer(gameId: $gameId, playerId: $playerId));
            $this->messageBus->dispatch(new PickCharacter(playerId: $playerId, gameId: $gameId, characterId: $characterIds[$index]));
            $this->messageBus->dispatch(new GetReady(playerId: $playerId, gameId: $gameId));
        }

        // Start the game
        $output->writeln('Starting the game...');
        $this->messageBus->dispatch(new StartGame($gameId));

        // Game loop - continues until no more tiles can be placed or deck is empty
        $gameDone = false;
        $turnCounter = 0;
        $maxTurns = 100; // Safety limit

        while (!$gameDone && $turnCounter < $maxTurns) {
            ++$turnCounter;
            $output->writeln("\n--- Turn {$turnCounter} ---");

            // Get the current player
            $currentPlayerId = $this->messageBus->dispatch(new GetCurrentPlayer($gameId));
            if ($currentPlayerId === null) {
                $output->writeln('Error: No current player found.');

                return Command::FAILURE;
            }

            $playerIndex = array_search($currentPlayerId, $playerIds, true);
            $output->writeln('Player ' . ($playerIndex + 1) . "'s turn");

            // Get current turn
            $currentTurnId = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            if ($currentTurnId === null) {
                $output->writeln('Error: No current turn found.');

                return Command::FAILURE;
            }

            // Generate a tile ID for this turn
            $tileId = Uuid::v7();

            try {
                // Pick a tile
                $output->writeln("Picking a tile with ID: {$tileId}");
                $this->messageBus->dispatch(new PickTile(
                    gameId: $gameId,
                    tileId: $tileId,
                    playerId: $currentPlayerId,
                    turnId: $currentTurnId,
                ));

                // Get the tile and field
                $tile = $this->messageBus->dispatch(new GetTile($tileId));
                $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));

                // Try to find a place for the tile by rotating it if necessary
                $place = null;
                $rotations = 0;
                $maxRotations = 4;

                while ($place === null && $rotations < $maxRotations) {
                    $place = $field->getRandomAvailablePlaceForTileOrientation($tile->getOrientation());

                    if ($place === null && $rotations < $maxRotations - 1) {
                        $output->writeln('Rotating tile...');
                        $this->messageBus->dispatch(new RotateTile(
                            tileId: $tileId,
                            topSide: TileSide::LEFT,
                            gameId: $gameId,
                            playerId: $currentPlayerId,
                            turnId: $currentTurnId,
                        ));
                        ++$rotations;
                    }
                }

                if ($place === null) {
                    $output->writeln("No place found for tile after {$rotations} rotations. Game over!");
                    $gameDone = true;

                    continue;
                }

                // Place the tile
                $output->writeln('Placing tile at ' . $place->toString());
                $this->messageBus->dispatch(new PlaceTile(
                    gameId: $gameId,
                    tileId: $tileId,
                    fieldPlace: $place,
                    playerId: $currentPlayerId,
                    turnId: $currentTurnId,
                ));
            } catch (\Throwable $e) {
                $output->writeln('Error during turn: ' . $e->getMessage());
                $gameDone = true;
            }

            // Flush changes to the database
            $this->entityManager->flush();
        }

        // Display the final state of the field
        $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
        $output->writeln("\nFinal field state:");
        $field->printField();

        if ($turnCounter >= $maxTurns) {
            $output->writeln("Game stopped after {$maxTurns} turns.");
        } else {
            $output->writeln("Game completed in {$turnCounter} turns.");
        }

        return Command::SUCCESS;
    }
}
