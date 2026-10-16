<?php

declare(strict_types=1);

namespace App\Api\Table\ListOpen;

use App\Api\Table\Tables;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Open tables: online, still in the lobby, with a free seat (and the caller's own tables, full or not), newest first.
 */
final readonly class Action
{
    private const int LIMIT = 50;

    public function __construct(
        private Connection $connection,
        private Tables $tables,
    ) {}

    #[Route('/tables', methods: ['GET'])]
    public function __invoke(HttpRequest $httpRequest): JsonResponse
    {
        $account = $this->tables->account($httpRequest);
        if ($account === null) {
            return Tables::anonymous();
        }
        $me = $account->getId()->toString();

        /** @var list<array{game_id: string, seats: int, taken: int|string, regent: bool, lord_karak: bool, random_heroes: bool, host_name: ?string, is_mine: bool, is_host: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT g.game_id, g.seats, g.regent, g.lord_karak, g.random_heroes,
                       json_array_length(g.players) AS taken,
                       (SELECT h.username FROM player.player h
                         WHERE h.game_id = g.game_id AND h.external_id = g.host_account_id LIMIT 1) AS host_name,
                       EXISTS (SELECT 1 FROM player.player m
                         WHERE m.game_id = g.game_id AND m.external_id = :me) AS is_mine,
                       g.host_account_id = :me AS is_host
                FROM game.game g
                WHERE g.online AND g.status = 'lobby'
                  AND (json_array_length(g.players) < g.seats
                       OR EXISTS (SELECT 1 FROM player.player m2 WHERE m2.game_id = g.game_id AND m2.external_id = :me))
                ORDER BY g.game_id DESC
                LIMIT :limit
            SQL, ['me' => $me, 'limit' => self::LIMIT], ['limit' => ParameterType::INTEGER]);

        return new JsonResponse(['tables' => array_map(static fn(array $row): array => [
            'gameId' => $row['game_id'],
            'seats' => (int) $row['seats'],
            'taken' => (int) $row['taken'],
            'regent' => (bool) $row['regent'],
            'lordKarak' => (bool) $row['lord_karak'],
            'randomHeroes' => (bool) $row['random_heroes'],
            'hostName' => $row['host_name'],
            'isMine' => (bool) $row['is_mine'],
            'isHost' => (bool) $row['is_host'],
        ], $rows)]);
    }
}
