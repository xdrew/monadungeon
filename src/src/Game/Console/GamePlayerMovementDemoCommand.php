<?php

declare(strict_types=1);

namespace App\Game\Console;

use App\Game\Field\Field;
use App\Game\Field\FieldPlace;
use App\Game\Field\GetAvailablePlacesForPlayer;
use App\Game\Field\GetField;
use App\Game\Field\GetTile;
use App\Game\Field\PickTile;
use App\Game\Field\PlaceTile;
use App\Game\Field\RotateTile;
use App\Game\Field\TileSide;
use App\Game\GameLifecycle\AddPlayer;
use App\Game\GameLifecycle\CreateGame;
use App\Game\GameLifecycle\GetCurrentPlayer;
use App\Game\GameLifecycle\NextTurn;
use App\Game\GameLifecycle\StartGame;
use App\Game\Movement\Commands\MovePlayer;
use App\Game\Movement\Commands\ResetPlayerPosition;
use App\Game\Player\GetReady;
use App\Game\Player\PickCharacter;
use App\Game\Turn\GetCurrentTurn;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Telephantast\MessageBus\MessageBus;

#[AsCommand('game:demo-movement')]
final class GamePlayerMovementDemoCommand extends Command
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
            ->setDescription('Demonstrates player movement using field transitions')
            ->addOption('players', 'p', InputOption::VALUE_OPTIONAL, 'Number of players', default: 2)
            ->addOption('tiles', 't', InputOption::VALUE_OPTIONAL, 'Number of tiles to place', default: 10)
            ->addOption('moves', 'm', InputOption::VALUE_OPTIONAL, 'Number of moves to simulate per player', default: 5)
            ->addOption('field-only', 'f', InputOption::VALUE_NONE, 'Only print the field state, without any other output')
            ->addOption('game-id', 'g', InputOption::VALUE_OPTIONAL, 'Specify an existing game ID to view its field state')
            ->addOption('no-clear-db', null, InputOption::VALUE_NONE, 'Do not clear the database before running the command')
            ->addOption('compact-view', 'c', InputOption::VALUE_NONE, 'Show a 3x3 compact view centered around the player');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check if field-only mode is enabled
        $fieldOnly = $input->getOption('field-only');
        $noClearDb = $input->getOption('no-clear-db');
        $gameIdOption = $input->getOption('game-id');
        $compactView = $input->getOption('compact-view');

        // Generate or use the provided game ID
        if ($gameIdOption) {
            try {
                $gameId = Uuid::fromString($gameIdOption);
            } catch (\InvalidArgumentException) {
                $output->writeln('<error>Invalid game ID format. Using default ID.</error>');
                $gameId = Uuid::fromString('c8beab98-c6c3-4c47-9f85-f0da3a80d492');
            }
        } else {
            $gameId = Uuid::fromString('c8beab98-c6c3-4c47-9f85-f0da3a80d492');
        }

        // When using an existing game ID or field-only mode, don't clear the database
        $shouldClearDb = !$noClearDb && !$gameIdOption;

        // If we're only displaying the field for an existing game, skip game creation
        if ($gameIdOption) {
            // Just show the field state and exit
            if (!$fieldOnly) {
                $output->writeln("Displaying field state for existing game: {$gameId}");
            }

            try {
                /** @var Field $field */
                $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));

                // Get the current player turn for the field display
                try {
                    $currentPlayerId = $this->messageBus->dispatch(new GetCurrentPlayer($gameId));
                    $field->printField(true, $currentPlayerId, $compactView);
                } catch (\Throwable) {
                    // If we can't get the current player, just show the field without highlights
                    $field->printField(false, null, $compactView);
                }

                if (!$fieldOnly) {
                    // Debug section: Print transitions for better understanding
                    $output->writeln("\nDebug: Transitions between tiles:");
                    $transitions = $field->getDebugTransitions();
                    foreach ($transitions as $from => $destinations) {
                        $output->writeln("From {$from} → " . implode(', ', $destinations));
                    }

                    // Show available field places
                    $output->writeln("\nAvailable field places for tile placement:");
                    foreach ($field->getAvailableFieldPlaces() as $index => $place) {
                        $output->writeln(" [{$index}] " . $place->toString());
                    }

                    // Show player positions
                    $output->writeln("\nPlayer positions:");
                    foreach ($field->getPlayerPositions() as $playerId => $position) {
                        $positionString = ($position instanceof FieldPlace)
                            ? $position->toString()
                            : (new FieldPlace($position['positionX'], $position['positionY']))->toString();
                        $output->writeln(" Player {$playerId}: {$positionString}");
                    }
                }

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $output->writeln('<error>Error retrieving field: ' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
        }

        // Clear the database if needed
        if ($shouldClearDb) {
            if (!$fieldOnly) {
                $output->writeln('Clearing database...');
            }

            $this->getApplication()?->doRun(
                new ArrayInput([
                    'command' => 'dbal:run-sql',
                    'sql' => <<<'SQL'
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
                        SQL,
                ]),
                $output,
            );
        }

        $numPlayers = (int) $input->getOption('players');
        $numTiles = (int) $input->getOption('tiles');
        $numMoves = (int) $input->getOption('moves');

        // Create player IDs
        $playerIds = [];
        $characterIds = [];
        for ($i = 0; $i < $numPlayers; ++$i) {
            $playerIds[] = Uuid::v7();
            $characterIds[] = Uuid::v7();
        }

        // Create the game
        if (!$fieldOnly) {
            $output->writeln("Creating game with ID: {$gameId}");
        }
        $this->messageBus->dispatch(new CreateGame($gameId, deckSize: $numTiles));

        // Add players to the game
        foreach ($playerIds as $index => $playerId) {
            if (!$fieldOnly) {
                $output->writeln('Adding player ' . ($index + 1) . " with ID: {$playerId}");
            }
            $this->messageBus->dispatch(new AddPlayer(gameId: $gameId, playerId: $playerId));
            $this->messageBus->dispatch(new PickCharacter(playerId: $playerId, gameId: $gameId, characterId: $characterIds[$index]));
            $this->messageBus->dispatch(new GetReady(playerId: $playerId, gameId: $gameId));
        }

        // Start the game
        if (!$fieldOnly) {
            $output->writeln('Starting the game...');
        }
        $this->messageBus->dispatch(new StartGame($gameId));

        // Initialize player positions at the starting position (0,0)
        $startingPosition = FieldPlace::fromString('0,0');
        foreach ($playerIds as $index => $playerId) {
            $this->messageBus->dispatch(new ResetPlayerPosition(
                gameId: $gameId,
                playerId: $playerId,
                position: $startingPosition,
            ));
        }

        if (!$fieldOnly) {
            $output->writeln("\n--- DEMONSTRATING GAMEPLAY: AUTOMATIC TILE PLACEMENT AND MOVEMENT ---");
            $output->writeln('Each time a player places a tile, they automatically move to it if possible.');
        }

        // Keep track of placed tile positions
        $placedTilePositions = [];

        // Main gameplay demonstration loop
        for ($turn = 1; $turn <= $numTiles; ++$turn) {
            if (!$fieldOnly) {
                $output->writeln("\n=== TURN {$turn} ===");
            }

            // Get the current player
            $currentPlayerId = $this->messageBus->dispatch(new GetCurrentPlayer($gameId));
            if ($currentPlayerId === null) {
                if (!$fieldOnly) {
                    $output->writeln('Error: No current player found.');
                }

                return Command::FAILURE;
            }

            $playerIndex = array_search($currentPlayerId, $playerIds, true);
            if (!$fieldOnly) {
                $output->writeln('Player ' . ($playerIndex + 1) . "'s turn");
            }

            // Get current turn ID
            $currentTurnId = $this->messageBus->dispatch(new GetCurrentTurn($gameId));
            if ($currentTurnId === null) {
                if (!$fieldOnly) {
                    $output->writeln('Error: No current turn found.');
                }

                return Command::FAILURE;
            }

            try {
                // Get player's current position
                $playerPosition = $this->getPlayerPosition($gameId, $currentPlayerId, $startingPosition);
                if (!$fieldOnly) {
                    $output->writeln('Player is currently at ' . $playerPosition->toString());
                }

                // Show current field state at the beginning of the turn
                if (!$fieldOnly) {
                    $output->writeln("\nCurrent field state at beginning of turn:");
                    $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                    $field->printField(true, $currentPlayerId, $compactView);
                }

                // Get all available places for this player using our new query
                $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                    gameId: $gameId,
                    playerId: $currentPlayerId,
                    messageBus: $this->messageBus,
                ));

                $moveToPlaces = $availablePlaces['moveTo'];
                $placeTilePlaces = $availablePlaces['placeTile'];

                if (!$fieldOnly) {
                    $output->writeln('Available move destinations: ' . \count($moveToPlaces));
                    $output->writeln('Available tile placement locations: ' . \count($placeTilePlaces));
                }

                if (empty($moveToPlaces)) {
                    if (!$fieldOnly) {
                        $output->writeln('No valid moves available for player. Skipping turn.');

                        // Add detailed diagnostics for debugging
                        $output->writeln("\n<info>DEBUG: Movement diagnostics</info>");
                        $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                        $diagnostics = $field->debugPlayerMovement($currentPlayerId);

                        $output->writeln('Player position: ' . $diagnostics['playerPosition']);
                        $output->writeln('Raw transitions: ' . implode(', ', $diagnostics['transitions']));

                        $output->writeln("\nValid moves:");
                        foreach ($diagnostics['validMoves'] as $dest => $info) {
                            $output->writeln(" - {$dest}: " . (\is_array($info) ? $info['reason'] : $info));
                        }

                        $output->writeln("\nInvalid moves:");
                        foreach ($diagnostics['invalidMoves'] as $dest => $info) {
                            $output->writeln(" - {$dest}: " . (\is_array($info) ? $info['reason'] : $info));
                        }

                        $output->writeln("\nDiagnostics:");
                        foreach ($diagnostics['diagnostics'] as $message) {
                            $output->writeln(" - {$message}");
                        }

                        if (isset($diagnostics['availableTransitions']) && !empty($diagnostics['availableTransitions'])) {
                            $output->writeln("\nAvailable transitions details:");
                            foreach ($diagnostics['availableTransitions'] as $dest => $details) {
                                $canTransition = $details['canTransition'] ? 'true' : 'false';
                                $hasOpening = $details['hasCompatibleOpening'] ? 'true' : 'false';
                                $output->writeln(" - {$dest}: canTransition={$canTransition}, hasCompatibleOpening={$hasOpening}");
                            }
                        }
                    }
                    $this->messageBus->dispatch(new NextTurn($gameId));

                    continue;
                }

                // Randomly select a destination from available places
                if (\count($placeTilePlaces)) {
                    $selectedDestination = $placeTilePlaces[array_rand($placeTilePlaces)];
                } else {
                    $selectedDestination = $moveToPlaces[array_rand($moveToPlaces)];
                }
                $destinationString = $selectedDestination->toString();

                if (!$fieldOnly) {
                    $output->writeln('Selecting destination from available options...');
                    $output->writeln("\033[38;5;201mSelected destination\033[0m " . $destinationString);
                }

                // Check if destination already has a tile
                $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                $placedTiles = $field->getPlacedTiles();

                if (!isset($placedTiles[$destinationString])) {
                    // No tile at destination - first place a tile, then move
                    if (!$fieldOnly) {
                        $output->writeln('No tile at destination. Placing a new tile first.');
                    }

                    // Generate a tile ID
                    $tileId = Uuid::v7();

                    // Pick a tile
                    $this->messageBus->dispatch(new PickTile(
                        gameId: $gameId,
                        tileId: $tileId,
                        playerId: $currentPlayerId,
                        turnId: $currentTurnId,
                    ));

                    // Get the tile
                    $tile = $this->messageBus->dispatch(new GetTile($tileId));

                    // Try rotating the tile up to 4 times to find a valid orientation
                    $validOrientation = false;
                    $availableOrientations = $field->getAvailableFieldPlacesOrientation();

                    // Get the required orientation for this position
                    $requiredOrientation = $availableOrientations[$destinationString] ?? null;

                    if ($requiredOrientation) {
                        if (!$fieldOnly) {
                            $output->writeln("Required orientation for position {$destinationString}: " . $requiredOrientation->toString());
                            $output->writeln('Current tile orientation: ' . $tile->getOrientation()->toString());
                        }

                        // First attempt: Try to match the required orientation pattern
                        // AND make sure it has an opening toward the player
                        $currentPlayerPos = $this->getPlayerPosition($gameId, $currentPlayerId, $startingPosition);
                        $adjacentSide = null;

                        // Determine the direction from player to destination
                        foreach ($currentPlayerPos->getAllSiblingsBySides() as $side => $adjacentPlace) {
                            if ($adjacentPlace->equals($selectedDestination)) {
                                $adjacentSide = $side;
                                break;
                            }
                        }

                        if ($adjacentSide !== null) {
                            $playerSide = TileSide::from($adjacentSide);
                            $newTileSide = $playerSide->getOppositeSide();

                            if (!$fieldOnly) {
                                $output->writeln('Direction from player: ' . $playerSide->name);
                                $output->writeln('Required opening on new tile: ' . $newTileSide->name);
                                $output->writeln('Current tile orientation: ' . $tile->getOrientation()->toString());
                                $output->writeln('Required field orientation: ' . $requiredOrientation->toString());

                                // Check condition for diagnostics
                                $hasOpeningTowardPlayer = $tile->getOrientation()->isOpenedSide($newTileSide);

                                $output->writeln('Checking initial condition:');
                                $output->writeln(' - Has opening toward player: ' . ($hasOpeningTowardPlayer ? '✅ Yes' : '❌ No'));
                            }

                            // Try to find an orientation that has an opening toward the player
                            if ($tile->rotateToMatchBothConditions($requiredOrientation, $newTileSide)) {
                                if (!$fieldOnly) {
                                    $output->writeln('✅ Found orientation that has an opening toward player!');
                                }
                                $validOrientation = true;
                            } else {
                                if (!$fieldOnly) {
                                    $output->writeln('❌ Could not find orientation with an opening toward player.');

                                    // Debug information about all possible orientations
                                    $output->writeln("\n<info>Orientation diagnostics:</info>");
                                    $output->writeln('Checking all possible orientations...');

                                    // Try all orientations and show why they fail
                                    $orientations = [];
                                    $originalOrientation = clone $tile->getOrientation();

                                    for ($i = 0; $i <= 3; ++$i) {
                                        $nextSide = match ($i) {
                                            0 => TileSide::TOP,    // Original orientation
                                            1 => TileSide::RIGHT,  // Rotate 90 degrees
                                            2 => TileSide::BOTTOM, // Rotate 180 degrees
                                            3 => TileSide::LEFT,   // Rotate 270 degrees
                                            default => TileSide::TOP
                                        };

                                        // Create a test orientation
                                        $testOrientation = $originalOrientation->getOrientationForTopSide($nextSide);
                                        $orientStr = $testOrientation->toString();

                                        // Check only for player opening
                                        $hasPlayerOpening = $testOrientation->isOpenedSide($newTileSide);

                                        $output->writeln(sprintf(
                                            'Rotation %d: %s - Has opening toward player: %s',
                                            $i,
                                            $orientStr,
                                            $hasPlayerOpening ? '✅' : '❌',
                                        ));
                                    }

                                    // This tile cannot be placed at this position with the current constraints
                                    $output->writeln("\n❗ This tile cannot be placed at the selected destination with any valid orientation.");
                                }
                            }
                        } else {
                            if (!$fieldOnly) {
                                $output->writeln('❌ Could not determine direction from player to destination.');
                            }
                        }
                    } else {
                        if (!$fieldOnly) {
                            $output->writeln("⚠️ No orientation requirements found for {$destinationString}");
                        }
                    }

                    // If we found a valid orientation, make sure we commit the rotation before placing
                    if ($validOrientation) {
                        if (!$fieldOnly) {
                            $output->writeln('Found a valid orientation for tile at ' . $destinationString);
                        }

                        // Apply the rotation to the server-side tile before placing
                        $this->messageBus->dispatch(new RotateTile(
                            tileId: $tileId,
                            // We don't need to specify a rotation since the tile is already rotated by our helper methods
                            topSide: TileSide::TOP, // Keep current orientation
                            gameId: $gameId,
                            playerId: $currentPlayerId,
                            turnId: $currentTurnId,
                        ));
                    } else {
                        if (!$fieldOnly) {
                            $output->writeln('Could not find valid orientation for tile at selected destination. Trying another approach.');
                        }
                        // Pick a different destination that already has a tile
                        $tiledDestinations = array_filter($moveToPlaces, static fn($place) => isset($placedTiles[$place->toString()]));

                        if (!empty($tiledDestinations)) {
                            $selectedDestination = $tiledDestinations[array_rand($tiledDestinations)];
                            $destinationString = $selectedDestination->toString();

                            if (!$fieldOnly) {
                                $output->writeln('Moving to existing tile at ' . $destinationString . ' instead.');
                            }

                            // Move the player to an existing tile
                            $this->messageBus->dispatch(new MovePlayer(
                                gameId: $gameId,
                                playerId: $currentPlayerId,
                                turnId: $currentTurnId,
                                fromPosition: $playerPosition,
                                toPosition: $selectedDestination,
                                ignoreMonster: false,
                            ));

                            if (!$fieldOnly) {
                                $output->writeln('Player moved to ' . $destinationString);
                            }

                            // End the turn
                            $this->messageBus->dispatch(new NextTurn($gameId));
                        } else {
                            if (!$fieldOnly) {
                                $output->writeln('No valid destinations found. Skipping turn.');
                            }
                            $this->messageBus->dispatch(new NextTurn($gameId));
                        }

                        continue;
                    }

                    // Place the tile
                    if (!$fieldOnly) {
                        $output->writeln('Placing tile at ' . $destinationString);
                    }

                    try {
                        $this->messageBus->dispatch(new PlaceTile(
                            gameId: $gameId,
                            tileId: $tileId,
                            fieldPlace: $selectedDestination,
                            playerId: $currentPlayerId,
                            turnId: $currentTurnId,
                        ));

                        // PlaceTile automatically triggers NextTurn and moves the player
                        $placedTilePositions[] = $selectedDestination;

                        if (!$fieldOnly) {
                            $output->writeln('Tile placed successfully and player moved to ' . $destinationString);
                        }
                    } catch (\Throwable $e) {
                        if (!$fieldOnly) {
                            $output->writeln('<error>Error placing tile: ' . $e->getMessage() . '</error>');

                            // Show detailed diagnostic information
                            $output->writeln("\n<info>Detailed Diagnostics:</info>");

                            $currentPlayerPos = $this->getPlayerPosition($gameId, $currentPlayerId, $startingPosition);
                            $output->writeln('Player position: ' . $currentPlayerPos->toString());
                            $output->writeln('Attempting to place at: ' . $selectedDestination->toString());

                            // Check if the player's position is adjacent to the target position
                            $isAdjacent = false;
                            $adjacentSide = null;
                            foreach ($currentPlayerPos->getAllSiblingsBySides() as $side => $adjacentPlace) {
                                if ($adjacentPlace->equals($selectedDestination)) {
                                    $isAdjacent = true;
                                    $adjacentSide = $side;
                                    break;
                                }
                            }

                            $output->writeln('Target is adjacent to player: ' . ($isAdjacent ? '✅ Yes' : '❌ No'));

                            if ($isAdjacent) {
                                // Check player's tile orientation
                                $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                                $playerTiles = $field->getPlacedTiles();
                                $playerPosString = $currentPlayerPos->toString();

                                if (isset($playerTiles[$playerPosString])) {
                                    $playerTileId = $playerTiles[$playerPosString];
                                    $playerTile = $this->messageBus->dispatch(new GetTile($playerTileId));

                                    $playerSide = TileSide::from($adjacentSide);
                                    $output->writeln('Direction from player to target: ' . $playerSide->name);

                                    // Check if player's tile has opening
                                    $output->writeln("Player's tile has opening in this direction: " .
                                        ($playerTile->hasOpenedSide($playerSide) ? '✅ Yes' : '❌ No'));

                                    // Check if new tile has opening toward player
                                    $newTileSide = $playerSide->getOppositeSide();
                                    $output->writeln('New tile needs opening on side: ' . $newTileSide->name);
                                    $output->writeln('New tile has required opening: ' .
                                        ($tile->hasOpenedSide($newTileSide) ? '✅ Yes' : '❌ No'));
                                }
                            }

                            // Suggest actions
                            $output->writeln("\n<info>Suggestions:</info>");
                            $output->writeln('1. Try rotating the tile to align openings.');
                            $output->writeln('2. Choose a different placement position.');
                            $output->writeln('3. Use placeTile with debug output for more detailed diagnostics.');
                        }
                        $this->messageBus->dispatch(new NextTurn($gameId));
                    }
                } else {
                    // Destination already has a tile - just move there
                    if (!$fieldOnly) {
                        $output->writeln('Destination already has a tile. Moving player.');
                    }

                    try {
                        // Move the player
                        $this->messageBus->dispatch(new MovePlayer(
                            gameId: $gameId,
                            playerId: $currentPlayerId,
                            turnId: $currentTurnId,
                            fromPosition: $playerPosition,
                            toPosition: $selectedDestination,
                            ignoreMonster: false,
                        ));

                        if (!$fieldOnly) {
                            $output->writeln('Player moved to ' . $destinationString);
                        }

                        // End the turn
                        $this->messageBus->dispatch(new NextTurn($gameId));
                    } catch (\Throwable $e) {
                        if (!$fieldOnly) {
                            $output->writeln('Error moving player: ' . $e->getMessage());
                        }
                        $this->messageBus->dispatch(new NextTurn($gameId));
                    }
                }

                // Show current field state after the turn
                if (!$fieldOnly) {
                    $output->writeln("\nField state after turn actions:");
                }

                try {
                    $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                    $field->printField(true, $currentPlayerId, $compactView);

                    // Print more detailed information about placement options
                    if (!$fieldOnly) {
                        $output->writeln("\nUpdated movement and placement options:");
                        $output->writeln('Player position: ' . $this->getPlayerPosition($gameId, $currentPlayerId, $startingPosition)->toString());

                        // Get the available destinations one more time (they may have changed)
                        $availablePlaces = $this->messageBus->dispatch(new GetAvailablePlacesForPlayer(
                            gameId: $gameId,
                            playerId: $currentPlayerId,
                            messageBus: $this->messageBus,
                        ));

                        $moveToPlaces = $availablePlaces['moveTo'];
                        $placeTilePlaces = $availablePlaces['placeTile'];

                        $output->writeln('Places player can move to: ' . \count($moveToPlaces));
                        foreach ($moveToPlaces as $index => $place) {
                            $output->writeln(" [{$index}] " . $place->toString() .
                                (isset($placedTiles[$place->toString()]) ? ' (has tile)' : ' (no tile)'));
                        }

                        $output->writeln('Places player can place tiles at: ' . \count($placeTilePlaces));
                        foreach ($placeTilePlaces as $index => $place) {
                            $output->writeln(" [{$index}] " . $place->toString());
                        }
                    }
                } catch (\Exception $e) {
                    if (!$fieldOnly) {
                        $output->writeln('Error displaying field: ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                if (!$fieldOnly) {
                    $output->writeln('Error during turn: ' . $e->getMessage());
                }

                // Try to move to next turn even if there was an error
                try {
                    $this->messageBus->dispatch(new NextTurn($gameId));
                } catch (\Throwable) {
                    // Ignore any errors when moving to next turn
                }
            }

            $this->entityManager->flush();
        }

        // Display final field state
        try {
            if ($fieldOnly) {
                // Just print the field
                try {
                    $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));

                    // Try to get current player ID for better display
                    try {
                        $currentPlayerId = $this->messageBus->dispatch(new GetCurrentPlayer($gameId));
                        $field->printField(true, $currentPlayerId, $compactView);
                    } catch (\Throwable) {
                        // If we can't get current player, just show field without highlights
                        $field->printField(false, null, $compactView);
                    }
                } catch (\Throwable) {
                    // No output in field-only mode
                }
            } else {
                $output->writeln("\nFinal field state:");

                try {
                    $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                    // Get the current player for the final display
                    $currentPlayerId = $this->messageBus->dispatch(new GetCurrentPlayer($gameId));
                    $field->printField(true, $currentPlayerId, $compactView);

                    // Debug section: Print transitions for better understanding
                    $output->writeln("\nDebug: Transitions between tiles:");
                    $transitions = $field->getDebugTransitions();
                    foreach ($transitions as $from => $destinations) {
                        $output->writeln("From {$from} → " . implode(', ', $destinations));
                    }
                } catch (\Throwable $e) {
                    $output->writeln('Error displaying final field: ' . $e->getMessage());
                }

                $field->printField(true, $currentPlayerId, false);

                $output->writeln("\nGameplay demonstration completed.");
            }
        } catch (\Throwable $e) {
            if (!$fieldOnly) {
                $output->writeln('Error in final output: ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @psalm-suppress ForbiddenCode, UnusedVariable, MixedArgument, UnnecessaryVarAnnotation
     */
    private function getPlayerPosition(Uuid $gameId, Uuid $playerId, FieldPlace $defaultPosition): FieldPlace
    {
        /** @var Field $field */
        $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));

        // Get all player positions (in a real game we'd query this from the field)
        $positions = $field->getPlayerPositions() ?? [];

        // Check if position exists for this player
        if (!isset($positions[$playerId->toString()])) {
            return $defaultPosition;
        }

        $posData = $positions[$playerId->toString()];

        // Handle both FieldPlace object and array with positionX and positionY
        if ($posData instanceof FieldPlace) {
            return $posData;
        }
        if (\is_array($posData) && isset($posData['positionX'], $posData['positionY'])) {
            return new FieldPlace($posData['positionX'], $posData['positionY']);
        }

        // Return default if the position data is invalid
        return $defaultPosition;
    }
}
