<?php

declare(strict_types=1);

namespace App\Api\Push;

use App\Game\AI\ServerAiDriver;
use App\Infrastructure\Push\Publisher;
use App\Infrastructure\Push\Topics;
use App\Infrastructure\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * After a successful command on an online game (the response is already sent), tell every browser at the
 * table that the game changed, the open-table list when a table changed, and let the server play the AI
 * seats whose turn it is now.
 */
final readonly class PublishGameChangeListener
{
    public function __construct(
        private Connection $connection,
        private Publisher $publisher,
        private ServerAiDriver $aiDriver,
    ) {}

    /**
     * The game a request was about: the route (/tables/{gameId}/…), the body, or the answer (a new table).
     *
     * @param array<mixed> $body
     */
    private static function gameId(Request $request, Response $response, array $body): ?string
    {
        $candidates = [$request->attributes->get('gameId'), $body['gameId'] ?? null];
        $answer = json_decode((string) $response->getContent(), true);
        if (\is_array($answer)) {
            $candidates[] = $answer['gameId'] ?? null;
        }
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && Uuid::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = rtrim($request->getPathInfo(), '/');
        if ($request->getMethod() !== 'POST' || !$response->isSuccessful()) {
            return;
        }
        $isTable = str_starts_with($path, '/api/tables');
        if (!$isTable && !str_starts_with($path, '/api/game/')) {
            return;
        }
        $body = json_decode($request->getContent(), true);
        $gameId = self::gameId($request, $response, \is_array($body) ? $body : []);
        if ($gameId === null) {
            return;
        }

        $game = $this->connection->fetchAssociative(
            'select online, status, current_turn_number from game.game where game_id = :id',
            ['id' => $gameId],
        );
        if ($game === false || !$game['online']) {
            return;
        }

        $this->publisher->publish(Topics::game($gameId), [
            'type' => 'game.changed',
            'gameId' => $gameId,
            'playerId' => \is_array($body) && \is_string($body['playerId'] ?? null) ? $body['playerId'] : null,
            'path' => substr($path, \strlen('/api')),
            'turn' => (int) $game['current_turn_number'],
            'status' => (string) $game['status'],
        ]);
        if ($isTable || $game['status'] === 'lobby') {
            $this->publisher->publish(Topics::TABLES, ['type' => 'tables.changed', 'gameId' => $gameId]);
        }

        if (\in_array($game['status'], ['started', 'turn_in_progress'], true)) {
            $this->aiDriver->drive(Uuid::fromString($gameId));
        }
    }
}
