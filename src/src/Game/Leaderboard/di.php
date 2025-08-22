<?php

declare(strict_types=1);

namespace App\Game\Leaderboard;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->extension('telephantast', [
        'entities' => [
            Leaderboard::class => null,
        ],
    ]);

    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(UpdateLeaderboardOnGameEnd::class)
            ->tag('messenger.message_handler');
};