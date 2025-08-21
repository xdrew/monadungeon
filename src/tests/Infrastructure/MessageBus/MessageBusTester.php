<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MessageBus;

use Telephantast\MessageBus\Envelope;
use Telephantast\MessageBus\Handler\CallableHandler;
use Telephantast\MessageBus\Handler\Mapping\HandlerDescriptor;
use Telephantast\Message\Message;
use Telephantast\MessageBus\HandlerRegistry\ArrayHandlerRegistry;
use Telephantast\MessageBus\MessageBus;
use Telephantast\MessageBus\MessageContext;

final readonly class MessageBusTester
{
    private function __construct(
        private ArrayHandlerRegistry $handlerRegistry,
    ) {}

    /**
     * @param callable(Message, MessageContext): mixed ...$handlers
     */
    public static function create(callable ...$handlers): self
    {
        $handlersByMessageClass = [];

        foreach ($handlers as $handler) {
            $descriptor = HandlerDescriptor::fromFunction(new \ReflectionFunction($handler(...)));

            foreach ($descriptor->messageClasses as $messageClass) {
                $handlersByMessageClass[$messageClass] = new CallableHandler($descriptor->id ?? uniqid(), $handler);
            }
        }

        return new self(new ArrayHandlerRegistry($handlersByMessageClass));
    }

    /**
     * @template THandlerResult
     * @template TMessage of Message
     * @param TMessage $message
     * @param (callable(TMessage): THandlerResult)|(callable(TMessage, MessageContext): THandlerResult) $handler
     * @return array{THandlerResult, list<Envelope|Message>}
     */
    public function handle(callable $handler, Message $message, bool $returnEnvelopes = false): mixed
    {
        $handlerRegistry = new TestHandlerRegistry($this->handlerRegistry);
        $messageBus = new MessageBus($handlerRegistry);
        $result = $handler($message, $messageBus->startContext($message));

        if ($returnEnvelopes) {
            return [$result, $handlerRegistry->envelopes];
        }

        return [$result, array_column($handlerRegistry->envelopes, 'message')];
    }
    
    public function createMessageContext(): MessageContext
    {
        $messageBus = new MessageBus($this->handlerRegistry);
        return $messageBus->startContext(new class implements Message {});
    }
    
    public function messageBus(): MessageBus
    {
        return new MessageBus($this->handlerRegistry);
    }
}
