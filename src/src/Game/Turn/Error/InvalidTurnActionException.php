<?php

declare(strict_types=1);

namespace App\Game\Turn\Error;

use App\Game\Turn\TurnAction;

final class InvalidTurnActionException extends \RuntimeException
{
    public function __construct(TurnAction $action, ?TurnAction $previousAction)
    {
        $previousActionName = $previousAction ? $previousAction->name : 'none';
        parent::__construct(sprintf('Cannot perform action %s after %s', $action->name, $previousActionName));
    }
}
