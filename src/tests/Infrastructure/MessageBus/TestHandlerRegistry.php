<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MessageBus;

use Telephantast\Message\Message;
use Telephantast\MessageBus\Envelope;
use Telephantast\MessageBus\Handler;
use Telephantast\MessageBus\HandlerRegistry;
use Telephantast\MessageBus\HandlerRegistry\ArrayHandlerRegistry;
use Telephantast\MessageBus\MessageContext;

/**
 * @internal
 * @psalm-internal App\Tests\Infrastructure\MessageBus
 * @implements Handler<mixed, Message<mixed>>
 */
final class TestHandlerRegistry extends HandlerRegistry implements Handler
{
    /**
     * @psalm-readonly-allow-private-mutation
     * @var list<Envelope>
     */
    public array $envelopes = [];

    public function __construct(private ArrayHandlerRegistry $handlerRegistry) {}

    public function id(): string
    {
        return self::class;
    }

    public function handle(MessageContext $messageContext): mixed
    {
        $this->envelopes[] = $messageContext->envelope;

        return null;
    }

    /**
     * @psalm-suppress InvalidReturnStatement
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return ?Handler<TResult, TMessage>
     */
    public function find(string $messageClass): ?Handler
    {
        return $this->handlerRegistry->find($messageClass) ?? $this;
    }
}
