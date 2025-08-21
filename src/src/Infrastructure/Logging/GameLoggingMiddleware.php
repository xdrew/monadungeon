<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Infrastructure\Uuid\Uuid;
use Telephantast\MessageBus\MessageContext;
use Telephantast\MessageBus\Middleware;
use Telephantast\MessageBus\Pipeline;

final readonly class GameLoggingMiddleware implements Middleware
{
    public function __construct(private GameLogger $gameLogger) {}

    /**
     * @throws \Throwable
     */
    public function handle(MessageContext $messageContext, Pipeline $pipeline): mixed
    {
        $message = $messageContext->getMessage();
        $gameId = $this->extractGameId($message);

        if ($gameId === null) {
            // Not a game-related message, pass through
            return $pipeline->continue();
        }

        $messageClass = $message::class;
        $startTime = microtime(true);

        try {
            $result = $pipeline->continue();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Skip logging for frequent read-only queries
            $skipPatterns = [
                'GetField',
                'GetDeck',
                'GetActivePlayers',
                'QueryPlayerInventory',
                'GetPlayerPositionOnField',
                'CanPlayerStillDoAnything',
            ];

            $shouldLog = true;
            foreach ($skipPatterns as $pattern) {
                if (str_contains($messageClass, $pattern)) {
                    $shouldLog = false;
                    break;
                }
            }

            if ($shouldLog) {
                // Log only essential data
                $logData = [
                    'duration_ms' => $duration,
                ];

                // Add minimal message data for important operations
                $importantFields = $this->getImportantFields($message);
                if ($importantFields !== []) {
                    $logData['data'] = $importantFields;
                }

                $this->gameLogger->log($gameId, 'info', $this->getShortMessageName($messageClass), $logData);
            }

            return $result;
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log error with essential details only
            $this->gameLogger->log($gameId, 'error', $this->getShortMessageName($messageClass) . ' failed', [
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'location' => basename($e->getFile()) . ':' . $e->getLine(),
                // Only include stack trace for non-user errors
                'trace' => $this->getMinimalStackTrace($e),
            ]);

            throw $e;
        }
    }

    private function extractGameId(object $message): ?string
    {
        // Check for gameId property
        if (property_exists($message, 'gameId')) {
            return (string) $message->gameId;
        }

        // Check for getId method (for game entities)
        if (method_exists($message, 'getId') && method_exists($message, 'getGameId')) {
            return (string) $message->getGameId();
        }

        // Check for getGameId method
        if (method_exists($message, 'getGameId')) {
            return (string) $message->getGameId();
        }

        // Try reflection to find gameId in any form
        $reflection = new \ReflectionObject($message);
        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($message);

            if ($property->getName() === 'gameId' && \is_string($value)) {
                return $value;
            }

            // Check if property contains an object with gameId
            if (\is_object($value) && method_exists($value, 'getGameId')) {
                return (string) $value->getGameId();
            }
        }

        return null;
    }

    private function getImportantFields(object $message): array
    {
        $data = [];
        $reflection = new \ReflectionObject($message);

        // Define important fields to log for each message type
        $importantFieldsByType = [
            'playerId' => true,
            'targetPlayerId' => true,
            'position' => true,
            'tileId' => true,
            'itemId' => true,
            'diceResult' => true,
            'damage' => true,
            'winner' => true,
            'loser' => true,
            'characterType' => true,
            'action' => true,
            'result' => true,
        ];

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            // Skip gameId as it's already in the log context
            if ($propertyName === 'gameId') {
                continue;
            }

            // Only log important fields
            if (isset($importantFieldsByType[$propertyName])) {
                $value = $property->getValue($message);
                $data[$propertyName] = $this->simplifyValue($value);
            }
        }

        return $data;
    }

    private function getShortMessageName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);

        return end($parts);
    }

    private function serializeResult(mixed $result): mixed
    {
        if ($result === null) {
            return null;
        }

        if (\is_scalar($result)) {
            return $result;
        }

        if (\is_array($result)) {
            return array_map($this->serializeValue(...), $result);
        }

        if (\is_object($result)) {
            return $this->serializeObject($result);
        }

        return (string) $result;
    }

    private function serializeValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            return array_map($this->serializeValue(...), $value);
        }

        if (\is_object($value)) {
            return $this->serializeObject($value);
        }

        return (string) $value;
    }

    private function simplifyValue(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            return array_map($this->simplifyValue(...), $value);
        }

        if (\is_object($value)) {
            // Simplify common objects to just their string representation
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if ($value instanceof Uuid || method_exists($value, '__toString')) {
                return (string) $value;
            }

            // For other objects, just return the class name
            return '<' . $value::class . '>';
        }

        return (string) $value;
    }

    private function serializeObject(object $object): array
    {
        // Simplified for error logging only
        if ($object instanceof \DateTimeInterface) {
            return ['value' => $object->format('Y-m-d H:i:s')];
        }

        if ($object instanceof Uuid || method_exists($object, '__toString')) {
            return ['value' => (string) $object];
        }

        return ['type' => $object::class];
    }

    private function getMinimalStackTrace(\Throwable $e): ?array
    {
        // Only include stack trace for application errors, not user errors
        $userErrorPatterns = [
            'ValidationException',
            'InvalidArgumentException',
            'NotFoundException',
            'AlreadyExistsException',
        ];

        foreach ($userErrorPatterns as $pattern) {
            if (str_contains($e::class, $pattern)) {
                return null;
            }
        }

        // For system errors, include only the first few app-level frames
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? '';

            // Skip vendor and cache files
            if (str_contains($file, '/vendor/') || str_contains($file, '/var/cache/')) {
                continue;
            }

            $frames[] = basename($file) . ':' . ($frame['line'] ?? 0) . ' ' . ($frame['function'] ?? '');

            if (\count($frames) >= 3) {
                break;
            }
        }

        return $frames === [] ? null : $frames;
    }
}
