<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MessageBus;

use Telephantast\MessageBus\Envelope;
use Telephantast\Message\Message;
use Telephantast\MessageBus\MessageBus;
use Telephantast\MessageBus\MessageContext;

/**
 * @template THandlerResult
 * @template TMessage of Message
 * @param TMessage $message
 * @param (callable(TMessage): THandlerResult)|(callable(TMessage, MessageContext): THandlerResult) $handler
 * @return array{THandlerResult, list<Envelope|Message>}
 */
function handle(callable $handler, Message $message, bool $returnEnvelopes = false): mixed
{
    return MessageBusTester::create()->handle($handler, $message, $returnEnvelopes);
}

/**
 * @template TResult
 * @template TMessage of Message<TResult>
 * @param TMessage|Envelope<TResult, TMessage> $messageOrEnvelope
 * @return MessageContext<TResult, TMessage>
 */
function startMessageContext(Envelope|Message $messageOrEnvelope = new TestMessage()): MessageContext
{
    return (new MessageBus())->startContext($messageOrEnvelope);
}
