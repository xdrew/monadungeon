<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Infrastructure\Push\Publisher;
use App\Infrastructure\Push\Topics;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * In an online game the server plays the AI seats itself: while the current seat is an AI, it plays the turn
 * and pushes the actions to every browser, which plays them back (`ai.turn`). Games in one browser keep
 * their browser-driven AI.
 */
final class ServerAiDriver
{
    public const int MAX_TURNS = 5;

    private bool $running = false;

    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly AiTurnTaker $ai,
        private readonly Publisher $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Plays every AI turn that is due now, up to MAX_TURNS in a row.
     */
    public function drive(Uuid $gameId): int
    {
        // the AI's own commands run inside this call: they must not start another round
        if ($this->running) {
            return 0;
        }
        $this->running = true;
        $played = 0;

        try {
            while ($played < self::MAX_TURNS) {
                $due = $this->dueSeat($gameId);
                if ($due === null) {
                    break;
                }
                [$playerId, $turn] = $due;
                $this->play($gameId, $playerId, $turn, false);
                ++$played;
                $after = $this->dueSeat($gameId);
                if ($after !== null && $after[1] === $turn && $after[0]->equals($playerId)) {
                    // the turn did not move on: stop rather than loop on a stuck seat
                    $this->logger->warning('ai.server_stuck', ['gameId' => $gameId->toString(), 'playerId' => $playerId->toString(), 'turn' => $turn]);
                    break;
                }
            }
        } finally {
            $this->running = false;
        }

        return $played;
    }

    /**
     * Plays one turn for a seat and pushes it; `standIn` when the AI plays for a human who is away.
     */
    public function play(Uuid $gameId, Uuid $playerId, int $turn, bool $standIn): void
    {
        try {
            $actions = $this->ai->executeTurn($gameId, $playerId);
        } catch (\Throwable $e) {
            $this->logger->error('ai.server_turn_failed', ['gameId' => $gameId->toString(), 'playerId' => $playerId->toString(), 'error' => $e->getMessage()]);
            $actions = [];
        }
        $this->logger->info('ai.server_turn', [
            'gameId' => $gameId->toString(),
            'playerId' => $playerId->toString(),
            'turn' => $turn,
            'actions' => \count($actions),
            'standIn' => $standIn,
        ]);
        $this->publisher->publish(Topics::game($gameId->toString()), [
            'type' => 'ai.turn',
            'gameId' => $gameId->toString(),
            'playerId' => $playerId->toString(),
            'turn' => $turn,
            'standIn' => $standIn,
            'actions' => $actions,
        ]);
    }

    /**
     * The AI seat whose turn it is in an online game in progress, with the turn number.
     *
     * @return array{Uuid, int}|null
     */
    private function dueSeat(Uuid $gameId): ?array
    {
        $game = $this->messageBus->dispatch(new GetGame($gameId));
        if (!$game->isOnline() || !$game->isInProgress()) {
            return null;
        }
        $current = $game->getCurrentPlayerId();
        if ($current === null || !$this->messageBus->dispatch(new GetPlayer($current))->isAi()) {
            return null;
        }

        return [$current, $game->getCurrentTurnNumber()];
    }
}
