<?php

declare(strict_types=1);

use App\Game\Testing\SetPlayerTestStateHandler;
use App\Game\Testing\SetupTestGameHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $services = $di->services();

    $services->set(SetPlayerTestStateHandler::class)
        ->autowire()
        ->tag('messenger.message_handler');

    $services->set(SetupTestGameHandler::class)
        ->autowire()
        ->tag('messenger.message_handler');
};
