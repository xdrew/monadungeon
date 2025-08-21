<?php

declare(strict_types=1);

use App\Game\Blockchain\CheckContractPermissionsCommand;
use App\Game\Blockchain\GameEndedHandler;
use App\Game\Blockchain\RegisterGameCommand;
use App\Game\Blockchain\TestBlockchainSubmissionCommand;
use App\Game\Blockchain\UnregisterGameCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $di): void {
    $di->services()
        ->set(GameEndedHandler::class)
        ->args([
            '$entityManager' => service('doctrine.orm.entity_manager'),
            '$logger' => service('logger'),
            '$rpcUrl' => env('MONAD_RPC_URL')->string(),
            '$contractAddress' => env('MONAD_CONTRACT_ADDRESS')->string(),
            '$privateKey' => env('MONAD_PRIVATE_KEY')->string()->default(''),
            '$chainId' => env('MONAD_CHAIN_ID')->int(),
        ])
        ->tag('messenger.message_handler');
        
    $di->services()
        ->set(TestBlockchainSubmissionCommand::class)
        ->args([
            '$logger' => service('logger'),
            '$rpcUrl' => env('MONAD_RPC_URL')->string(),
            '$contractAddress' => env('MONAD_CONTRACT_ADDRESS')->string(),
            '$privateKey' => env('MONAD_PRIVATE_KEY')->string()->default(''),
            '$chainId' => env('MONAD_CHAIN_ID')->int(),
        ])
        ->tag('console.command');
        
    $di->services()
        ->set(RegisterGameCommand::class)
        ->args([
            '$logger' => service('logger'),
            '$rpcUrl' => env('MONAD_RPC_URL')->string(),
            '$contractAddress' => env('MONAD_CONTRACT_ADDRESS')->string(),
            '$privateKey' => env('MONAD_PRIVATE_KEY')->string()->default(''),
            '$chainId' => env('MONAD_CHAIN_ID')->int(),
        ])
        ->tag('console.command');
        
    $di->services()
        ->set(UnregisterGameCommand::class)
        ->args([
            '$logger' => service('logger'),
            '$rpcUrl' => env('MONAD_RPC_URL')->string(),
            '$contractAddress' => env('MONAD_CONTRACT_ADDRESS')->string(),
            '$privateKey' => env('MONAD_PRIVATE_KEY')->string()->default(''),
            '$chainId' => env('MONAD_CHAIN_ID')->int(),
        ])
        ->tag('console.command');
        
    $di->services()
        ->set(CheckContractPermissionsCommand::class)
        ->args([
            '$logger' => service('logger'),
            '$rpcUrl' => env('MONAD_RPC_URL')->string(),
            '$contractAddress' => env('MONAD_CONTRACT_ADDRESS')->string(),
            '$privateKey' => env('MONAD_PRIVATE_KEY')->string()->default(''),
            '$chainId' => env('MONAD_CHAIN_ID')->int(),
        ])
        ->tag('console.command');
};