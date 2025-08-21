<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiLoggingMiddleware implements EventSubscriberInterface
{
    private array $requestData = [];

    public function __construct(private readonly LoggerInterface $apiLogger) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
            KernelEvents::EXCEPTION => ['onKernelException', -256],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // Skip logging for specific endpoints
        $uri = $request->getRequestUri();
        if (str_contains($uri, '/turns') || str_contains($uri, '/action-log')) {
            return;
        }

        $requestId = $this->generateRequestId();
        $startTime = microtime(true);

        $request->attributes->set('_api_request_id', $requestId);
        $request->attributes->set('_api_request_start', $startTime);

        $payload = $this->extractRequestPayload($request);

        $logData = [
            'request_id' => $requestId,
            'timestamp' => date('Y-m-d H:i:s.v'),
            'type' => 'request',
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'payload' => $payload,
            'client_ip' => $request->getClientIp(),
        ];

        $this->requestData[$requestId] = [
            'start_time' => $startTime,
            'request_data' => $logData,
        ];

        $this->apiLogger->info('API Request', $logData);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $requestId = $request->attributes->get('_api_request_id');
        $startTime = $request->attributes->get('_api_request_start');

        if (!$requestId || !isset($this->requestData[$requestId])) {
            return;
        }

        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;
        $responsePayload = $this->extractResponsePayload($response);

        $logData = [
            'request_id' => $requestId,
            'timestamp' => date('Y-m-d H:i:s.v'),
            'type' => 'response',
            'status_code' => $response->getStatusCode(),
            'payload' => $responsePayload,
        ];

        $this->apiLogger->info('API Response', $logData);

        // Clean up request data
        unset($this->requestData[$requestId]);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        $requestId = $request->attributes->get('_api_request_id');
        $startTime = $request->attributes->get('_api_request_start');

        if (!$requestId || !isset($this->requestData[$requestId])) {
            return;
        }

        $duration = $startTime ? round((microtime(true) - $startTime) * 1000, 2) : null;

        $logData = [
            'request_id' => $requestId,
            'timestamp' => date('Y-m-d H:i:s.v'),
            'type' => 'exception',
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->getFilteredStackTrace($exception),
        ];

        $this->apiLogger->error('API Exception', $logData);

        // Clean up request data
        unset($this->requestData[$requestId]);
    }

    private function generateRequestId(): string
    {
        return uniqid('api_', true);
    }

    private function extractRequestPayload(Request $request): mixed
    {
        $contentType = $request->headers->get('Content-Type', '');
        $content = $request->getContent();

        // Handle JSON payload
        if (str_contains($contentType, 'application/json')) {
            if (empty($content)) {
                return null;
            }

            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                return $this->sanitizePayload($decoded);
            } catch (\JsonException) {
                return ['_raw' => $content, '_error' => 'Invalid JSON'];
            }
        }

        // Handle form data
        if (str_contains($contentType, 'application/x-www-form-urlencoded') ||
            str_contains($contentType, 'multipart/form-data')) {
            $data = array_merge($request->request->all(), $request->files->all());
            return $this->sanitizePayload($data);
        }

        // Handle query parameters for GET requests
        if ($request->getMethod() === 'GET' && $request->query->count() > 0) {
            return $this->sanitizePayload($request->query->all());
        }

        // Return raw content for other types
        if (!empty($content)) {
            return ['_raw' => $this->truncateContent($content)];
        }

        return null;
    }

    private function extractResponsePayload(Response $response): mixed
    {
        $contentType = $response->headers->get('Content-Type', '');
        $content = $response->getContent();

        if (empty($content)) {
            return null;
        }

        // Handle JSON response
        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                return $this->sanitizePayload($decoded);
            } catch (\JsonException) {
                return ['_raw' => $this->truncateContent($content), '_error' => 'Invalid JSON'];
            }
        }

        // For non-JSON responses, return truncated content
        return ['_raw' => $this->truncateContent($content)];
    }

    private function sanitizePayload(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitized[$key] = $this->sanitizeValue($key, $value);
            }
            return $sanitized;
        }

        return $data;
    }

    private function sanitizeValue(string|int $key, mixed $value): mixed
    {
        // List of sensitive field names (case-insensitive)
        $sensitiveFields = [
            'password',
            'passwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'auth',
            'authorization',
            'credential',
            'private_key',
            'access_token',
            'refresh_token',
            'session',
            'cookie',
            'ssn',
            'social_security',
            'credit_card',
            'cvv',
            'pin',
        ];

        // Only check for sensitive data if key is a string
        if (is_string($key)) {
            $lowerKey = strtolower($key);

            // Check if field contains sensitive data
            foreach ($sensitiveFields as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    return '[REDACTED]';
                }
            }
        }

        // Recursively sanitize arrays and objects
        if (is_array($value)) {
            return $this->sanitizePayload($value);
        }

        // Truncate long strings
        if (is_string($value) && strlen($value) > 1000) {
            return substr($value, 0, 1000) . '... [TRUNCATED]';
        }

        return $value;
    }

    private function filterHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'set-cookie',
            'x-auth-token',
            'x-api-key',
            'x-access-token',
        ];

        $filtered = [];
        foreach ($headers as $name => $values) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $sensitiveHeaders, true)) {
                $filtered[$name] = ['[REDACTED]'];
            } else {
                $filtered[$name] = $values;
            }
        }

        return $filtered;
    }

    private function truncateContent(string $content): string
    {
        if (strlen($content) > 2000) {
            return substr($content, 0, 2000) . '... [TRUNCATED]';
        }

        return $content;
    }

    private function getFilteredStackTrace(\Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $filtered = [];

        foreach (array_slice($trace, 0, 10) as $frame) {
            $file = $frame['file'] ?? '';
            
            // Skip vendor files to reduce noise
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            $filtered[] = [
                'file' => basename($file),
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];

            // Limit to first 5 application frames
            if (count($filtered) >= 5) {
                break;
            }
        }

        return $filtered;
    }
}