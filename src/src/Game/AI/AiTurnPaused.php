<?php

declare(strict_types=1);

namespace App\Game\AI;

/**
 * Karak: Regent — the AI's turn waits for a human: the hero it summoned to a duel rolls (and picks the loot
 * if he wins) himself. Thrown by the AI's next request; the turn goes on when it is asked again.
 */
final class AiTurnPaused extends \RuntimeException {}
