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
use App\Game\Player\GetReady;
use App\Game\Player\PickCharacter;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * @psalm-suppress ForbiddenCode, UnusedVariable, UnnecessaryVarAnnotation
 */
#[AsCommand('game:run')]
final class GameRunConsoleCommand extends Command
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
            // ...
            ->addArgument('size', InputArgument::OPTIONAL, 'Deck size', default: 100);
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

        //  rm -rf migrations/* && sf d:d:d -f && sf d:d:c && sf d:m:diff && sf d:m:mi -n

        $deckSize = (int) $input->getArgument('size');
        $gameId = Uuid::fromString('0c9a14d1-fb5e-4ac4-8ab8-1ff1d27fd3e9');
        $playerId = Uuid::fromString('6a859512-a550-43a8-8ebd-e41c75e62506');
        $characterId = Uuid::fromString('58246be6-ea2a-42d0-b2ce-4f21aecbb9de');
        $tileIds = array_map(static fn($_): Uuid => Uuid::v7(), array_fill(0, $deckSize, null));

        $this->messageBus->dispatch(new CreateGame($gameId, deckSize: $deckSize));
        $this->messageBus->dispatch(new AddPlayer(gameId: $gameId, playerId: $playerId));
        $this->messageBus->dispatch(new PickCharacter(playerId: $playerId, gameId: $gameId, characterId: $characterId));
        $this->messageBus->dispatch(new GetReady(playerId: $playerId, gameId: $gameId));
        $rotates = 0;
        $placed = 0;
        foreach ($tileIds as $tileId) {
            try {
                $this->messageBus->dispatch(new PickTile($gameId, $tileId));
            } catch (\Throwable) {
                break;
            }

            /** @var Field $field */
            $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
            /** @var Tile $tile */
            $tile = $this->messageBus->dispatch(new GetTile($tileId));

            for ($i = 0; $i < 4; ++$i) {
                $place = $field->getRandomAvailablePlaceForTileOrientation($tile->getOrientation());
                if ($place === null) {
                    $this->messageBus->dispatch(new RotateTile($tileId, TileSide::LEFT));
                    ++$rotates;
                    $output->writeln("\n" . $rotates . ' rotated tile ' . $tileId);
                }
            }
            if ($place === null) {
                $output->writeln("\nno place for tile " . $tile->getOrientation()->getCharacter(false));

                $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
                $field->printField();

                return Command::FAILURE;
            }

            try {
                $this->messageBus->dispatch(new PlaceTile(
                    $gameId,
                    $tileId,
                    $place,
                ));
                $output->write("\rplaced tile " . ++$placed);
            } catch (\Throwable $e) {
                $output->writeln($e::class);

                continue;
            }
        }

        /** @var Field $field */
        $field = $this->messageBus->dispatch(new GetField(gameId: $gameId));
        $field->printField();

        return self::SUCCESS;
    }
}
