<?php

declare(strict_types=1);

namespace App\Game\AI;

use App\Game\GameLifecycle\GetGame;
use App\Game\Player\GetPlayer;
use App\Game\Player\MarkSeatAway;
use App\Game\Turn\EndTurn;
use App\Infrastructure\Push\Publisher;
use App\Infrastructure\Push\Topics;
use App\Infrastructure\Uuid\Uuid;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\MessageBus;

/**
 * In an online game the server plays the AI seats itself: while the current seat is an AI (or a human who is
 * away), it plays the turn and pushes the actions to every browser, which plays them back (`ai.turn`). A turn
 * that runs out of time is played for its seat as well, and the seat is away from then on (standIn).
 * Games in one browser keep their browser-driven AI.
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
                [$playerId, $turn, $standIn] = $due;
                $this->play($gameId, $playerId, $turn, $standIn);
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
     * The current turn of an online table ran out of time: the AI plays it for its seat, which is away from now
     * on, then every turn that is due after it. False when nothing was overdue (any more).
     */
    public function standIn(Uuid $gameId, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if ($this->running) {
            return false;
        }
        $game = $this->messageBus->dispatch(new GetGame($gameId));
        $deadline = $game->getTurnDeadline();
        $current = $game->getCurrentPlayerId();
        if (!$game->isOnline() || !$game->isInProgress() || $deadline === null || $deadline > $now || $current === null) {
            return false;
        }
        // nobody is at the table any more: the game waits for someone to come back
        if (!$this->anyHumanPresent($game)) {
            return false;
        }
        $turn = $game->getCurrentTurnNumber();
        $turnId = $game->getCurrentTurnId();
        $this->logger->info('turn.overdue', ['gameId' => $gameId->toString(), 'playerId' => $current->toString(), 'turn' => $turn]);
        if ($this->messageBus->dispatch(new MarkSeatAway($current, $gameId))) {
            $this->logger->info('seat.away', ['gameId' => $gameId->toString(), 'playerId' => $current->toString()]);
        }

        $this->running = true;

        try {
            $this->play($gameId, $current, $turn, true);
            $after = $this->messageBus->dispatch(new GetGame($gameId));
            if ($after->getCurrentTurnNumber() === $turn && $after->isInProgress() && $turnId !== null) {
                // the AI could not finish the turn (a choice it does not make): the turn ends as it stands
                $this->logger->warning('turn.forced_end', ['gameId' => $gameId->toString(), 'playerId' => $current->toString(), 'turn' => $turn]);
                $this->messageBus->dispatch(new EndTurn($turnId, $gameId, $current));
            }
        } catch (\Throwable $e) {
            $this->logger->error('turn.stand_in_failed', ['gameId' => $gameId->toString(), 'error' => $e->getMessage()]);
        } finally {
            $this->running = false;
        }
        $this->drive($gameId);

        return true;
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

    private function anyHumanPresent(object $game): bool
    {
        foreach ($game->getPlayers() as $playerId) {
            $player = $this->messageBus->dispatch(new GetPlayer($playerId));
            if (!$player->isAi() && !$player->isAway()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The seat the server plays now in an online game in progress (an AI, or a human who is away), with the
     * turn number and whether it stands in for a human.
     *
     * @return array{Uuid, int, bool}|null
     */
    private function dueSeat(Uuid $gameId): ?array
    {
        $game = $this->messageBus->dispatch(new GetGame($gameId));
        if (!$game->isOnline() || !$game->isInProgress()) {
            return null;
        }
        $current = $game->getCurrentPlayerId();
        if ($current === null) {
            return null;
        }
        $player = $this->messageBus->dispatch(new GetPlayer($current));
        if (!$player->isAi() && !$player->isAway()) {
            return null;
        }
        // nobody is at the table: the game moves on only as its turns run out (standIn), not all at once
        if (!$player->isAi() && !$this->anyHumanPresent($game)) {
            return null;
        }

        return [$current, $game->getCurrentTurnNumber(), !$player->isAi()];
    }
}
