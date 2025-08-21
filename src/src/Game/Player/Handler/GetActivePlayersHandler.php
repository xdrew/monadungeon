<?php

declare(strict_types=1);

namespace App\Game\Player\Handler;

use App\Game\Player\GetActivePlayers;
use App\Game\Player\Player;
use Doctrine\ORM\EntityManagerInterface;
use Telephantast\MessageBus\Handler\Mapping\Handler;

final readonly class GetActivePlayersHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Handle the GetActivePlayers message to retrieve player data for a game.
     *
     * @param GetActivePlayers $message The message
     * @return array<Player> An array of Player entities for the game
     */
    #[Handler]
    public function __invoke(GetActivePlayers $message): array
    {
        /** @var array<Player> $players */
        $players = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Player::class, 'p')
            ->where('p.gameId = :gameId')
            ->setParameter('gameId', $message->gameId->toString())
            ->getQuery()
            ->getResult();

        return $players;
    }
}
