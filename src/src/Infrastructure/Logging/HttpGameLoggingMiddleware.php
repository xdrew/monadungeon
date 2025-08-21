<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class HttpGameLoggingMiddleware implements EventSubscriberInterface
{
    private array $requestData = [];

    public function __construct(private readonly GameLogger $gameLogger) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
            KernelEvents::EXCEPTION => ['onKernelException', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $gameId = $this->extractGameIdFromRequest($request);

        if ($gameId === null) {
            return;
        }

        $requestId = uniqid('req_', true);
        $request->attributes->set('_game_request_id', $requestId);
        $request->attributes->set('_game_id', $gameId);
        $request->attributes->set('_game_request_start', microtime(true));

        $this->requestData[$requestId] = [
            'gameId' => $gameId,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'body' => $this->getRequestBody($request),
        ];

        // Only log the request start with minimal data
        $this->gameLogger->log($gameId, 'debug', 'HTTP ' . $request->getMethod(), [
            'uri' => $this->getSimplifiedUri($request->getRequestUri()),
        ]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $requestId = $request->attributes->get('_game_request_id');
        $gameId = $request->attributes->get('_game_id');
        $startTime = (float) $request->attributes->get('_game_request_start');

        if ($gameId === null || $requestId === null) {
            return;
        }

        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

        // Only log errors or slow requests
        if ($response->getStatusCode() >= 400 || $duration > 1000) {
            $logLevel = $response->getStatusCode() >= 400 ? 'error' : 'warning';
            $this->gameLogger->log((string) $gameId, $logLevel, 'HTTP ' . $response->getStatusCode(), [
                'uri' => $this->getSimplifiedUri($request->getRequestUri()),
                'duration_ms' => $duration,
                // Only include body for errors
                'body' => $response->getStatusCode() >= 400 ? $this->getMinimalResponseBody($response) : null,
            ]);
        }

        unset($this->requestData[(string) $requestId]);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();

        $requestId = $request->attributes->get('_game_request_id');
        $gameId = $request->attributes->get('_game_id');
        $startTime = (float) $request->attributes->get('_game_request_start');

        if ($gameId === null || $requestId === null) {
            return;
        }

        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

        $this->gameLogger->log((string) $gameId, 'error', 'HTTP Exception', [
            'uri' => $this->getSimplifiedUri($request->getRequestUri()),
            'duration_ms' => $duration,
            'error' => $exception->getMessage(),
            'location' => basename($exception->getFile()) . ':' . $exception->getLine(),
        ]);

        unset($this->requestData[(string) $requestId]);
    }

    private function extractGameIdFromRequest(Request $request): ?string
    {
        // Try to extract from route parameters
        $gameId = $request->attributes->get('gameId');
        if ($gameId !== null) {
            return (string) $gameId;
        }

        // Try to extract from request body
        $content = $request->getContent();
        if ($content !== '') {
            try {
                $data = json_decode($content, true);
                if (\is_array($data) && isset($data['gameId'])) {
                    return (string) $data['gameId'];
                }
            } catch (\Exception) {
                // Ignore JSON decode errors
            }
        }

        // Try to extract from query parameters
        if ($request->query->has('gameId')) {
            return (string) $request->query->get('gameId');
        }

        // Try to match game ID from URL pattern
        $pathInfo = $request->getPathInfo();
        if (preg_match('/\/game\/([a-f0-9\-]{36})/', $pathInfo, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getRequestBody(Request $request): mixed
    {
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains((string) $contentType, 'application/json')) {
            try {
                return json_decode($request->getContent(), true);
            } catch (\Exception) {
                return $request->getContent();
            }
        }

        if (str_contains((string) $contentType, 'application/x-www-form-urlencoded')
            || str_contains((string) $contentType, 'multipart/form-data')) {
            return $request->request->all();
        }

        return $request->getContent();
    }

    private function getMinimalResponseBody(Response $response): mixed
    {
        $contentType = $response->headers->get('Content-Type', '');
        $content = (string) $response->getContent();

        if (str_contains((string) $contentType, 'application/json')) {
            try {
                $data = json_decode($content, true);
                // Only return error message if present
                if (\is_array($data) && isset($data['error'])) {
                    return ['error' => $data['error']];
                }
                if (\is_array($data) && isset($data['message'])) {
                    return ['message' => $data['message']];
                }

                return 'JSON response';
            } catch (\Exception) {
                return 'Invalid JSON';
            }
        }

        // For non-JSON, just return a truncated version
        if (\strlen($content) > 200) {
            return substr($content, 0, 200) . '...';
        }

        return $content;
    }

    private function getSimplifiedUri(string $uri): string
    {
        // Remove query parameters and simplify URIs
        $parts = explode('?', $uri);
        $path = $parts[0];

        // Replace UUIDs with placeholder
        $path = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', '{id}', $path);

        return $path;
    }

    // Removed filterCallStack method as it's no longer needed
}
