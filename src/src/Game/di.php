<?php

declare(strict_types=1);

namespace App\CourseOrganization;

use App\Game\Bag\Bag;
use App\Game\Bag\DoctrineDBAL\InventoryJsonType;
use App\Game\Battle\Battle;
use App\Game\Console\GamePlayerMovementDemoCommand;
use App\Game\Console\GameRunConsoleCommand;
use App\Game\Console\GameRunTurnBasedConsoleCommand;
use App\Game\Console\TestGameLoggingCommand;
use App\Game\Deck\Deck;
use App\Game\Deck\DoctrineDBAL\DeckTileArrayJsonType;
use App\Game\Field\DoctrineDBAL\FieldPlaceArrayJsonType;
use App\Game\Field\DoctrineDBAL\ItemMapType;
use App\Game\Field\DoctrineDBAL\TileFeatureArrayType;
use App\Game\Field\DoctrineDBAL\TileOrientationMapType;
use App\Game\Field\DoctrineDBAL\TileOrientationType;
use App\Game\Field\Field;
use App\Game\Field\SymfonySerializer\FieldPlaceNormalizer;
use App\Game\Field\Tile;
use App\Game\GameLifecycle\Game;
use App\Game\Item\DoctrineDBAL\ItemArrayJsonType;
use App\Game\Item\DoctrineDBAL\ItemMapJsonType;
use App\Game\Movement\DoctrineDBAL\PlayerPositionMapType;
use App\Game\Movement\Movement;
use App\Game\Player\Player;
use App\Game\Turn\GameTurn;
use App\Game\Turn\Repository\GameTurnRepository;
use App\Game\AI\VirtualPlayer;
use App\Game\AI\VirtualPlayerSimple;
use App\Game\AI\VirtualPlayerImproved;
use App\Game\AI\SmartVirtualPlayer;
use App\Game\AI\VirtualPlayerStrategy;
use App\Game\AI\BasicVirtualPlayerStrategy;
use App\Game\AI\VirtualPlayerApiClient;
use App\Game\AI\EnhancedAIPlayer;
use App\Game\AI\AIPlayerManager;
use App\Game\AI\AIConfiguration;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Telephantast\DoctrinePersistence\DoctrineOrmEntityFinderAndSaver;
use Telephantast\DoctrinePersistence\DoctrineOrmTransactionProvider;
use Telephantast\DoctrinePersistence\DoctrinePostgresOutboxStorage;
use App\Infrastructure\Transaction\SafeDoctrineOrmTransactionProvider;

return static function (ContainerConfigurator $di): void {
    $di->extension('telephantast', [
        'entity_finder_id' => DoctrineOrmEntityFinderAndSaver::class,
        'entity_saver_id' => DoctrineOrmEntityFinderAndSaver::class,
        'async' => [
            'host' => '%env(string:key:host:url:TELEPHANTAST_TRANSPORT_URL)%',
            'port' => '%env(int:key:port:url:TELEPHANTAST_TRANSPORT_URL)%',
            'user' => '%env(string:key:user:url:TELEPHANTAST_TRANSPORT_URL)%',
            'password' => '%env(string:key:pass:url:TELEPHANTAST_TRANSPORT_URL)%',
            'vhost' => '%env(string:key:path:url:TELEPHANTAST_TRANSPORT_URL)%',
            'heartbeat' => '%env(int:key:heartbeat:query_string:TELEPHANTAST_TRANSPORT_URL)%',
            'outbox' => [
                'transaction_provider_id' => SafeDoctrineOrmTransactionProvider::class,
                'storage_id' => DoctrinePostgresOutboxStorage::class,
            ],
        ],
        'entities' => [
            Game::class => null,
            Player::class => null,
            Field::class => null,
            Tile::class => null,
            Deck::class => null,
            Bag::class => null,
            GameTurn::class => null,
            Battle::class => null,
            Movement::class => null,
        ],
    ]);

    $di->extension('doctrine', [
        'dbal' => [
            'types' => [
                TileOrientationType::class => TileOrientationType::class,
                TileFeatureArrayType::class => TileFeatureArrayType::class,
                FieldPlaceArrayJsonType::class => FieldPlaceArrayJsonType::class,
                DeckTileArrayJsonType::class => DeckTileArrayJsonType::class,
                TileOrientationMapType::class => TileOrientationMapType::class,
                PlayerPositionMapType::class => PlayerPositionMapType::class,
                ItemMapType::class => ItemMapType::class,
                InventoryJsonType::class => InventoryJsonType::class,
                ItemArrayJsonType::class => ItemArrayJsonType::class,
                ItemMapJsonType::class => ItemMapJsonType::class,
            ],
        ],
    ]);

    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(DoctrinePostgresOutboxStorage::class)
        ->set(DoctrineOrmTransactionProvider::class)
        ->set(SafeDoctrineOrmTransactionProvider::class)
        ->set(DoctrineOrmEntityFinderAndSaver::class)
        ->set(FieldPlaceNormalizer::class)
        ->set(GameRunConsoleCommand::class)
        ->set(GameTurnRepository::class)
        ->set(GameRunTurnBasedConsoleCommand::class)
        ->set(GamePlayerMovementDemoCommand::class)
        ->set(TestGameLoggingCommand::class)
            ->arg('$projectDir', '%kernel.project_dir%')
        // AI Strategy Configuration
        ->set(BasicVirtualPlayerStrategy::class)
        ->alias(VirtualPlayerStrategy::class, BasicVirtualPlayerStrategy::class)
        
        // Legacy AI Players
        ->set(VirtualPlayer::class)
        ->set(VirtualPlayerSimple::class)
        ->set(VirtualPlayerImproved::class)
        
        // Smart Virtual Player (Currently Active)
        ->set(SmartVirtualPlayer::class)
        
        // Virtual Player API Client
        ->set(VirtualPlayerApiClient::class)
            ->arg('$httpKernel', service('kernel'))
        
        // Enhanced AI Player (Future)
        ->set(EnhancedAIPlayer::class)
        
        // AI Player Manager with configurable default strategy
        ->set(AIPlayerManager::class)
            ->call('setDefaultStrategy', ['%env(string:default:balanced:AI_DEFAULT_STRATEGY)%']);
};
