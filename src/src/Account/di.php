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
        ->set(CurrentAccount::class)
        ->alias(Google\GoogleAccounts::class, AccountService::class)
        ->set(Google\GoogleSignIn::class)
        // Google sign-in: off without a client id; "fake" in dev/test signs in without Google (the e2e)
        ->set(Google\GoogleHttpClient::class)
            ->arg('$clientId', '%env(GOOGLE_CLIENT_ID)%')
            ->arg('$clientSecret', '%env(GOOGLE_CLIENT_SECRET)%')
        ->set(Google\FakeGoogleClient::class)
        ->set(Google\GoogleClientChooser::class)
            ->arg('$clientId', '%env(GOOGLE_CLIENT_ID)%')
            ->arg('$appEnv', '%kernel.environment%');
};
