<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->set(AccountService::class)
        ->set(CurrentAccount::class);
};
