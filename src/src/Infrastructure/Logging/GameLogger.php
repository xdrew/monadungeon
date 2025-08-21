<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\LoggerInterface;

final class GameLogger
{
    private readonly string $logDirectory;

    /**
     * @var array<string, resource>
     */
    private array $fileHandles = [];

    public function __construct(string $logDirectory, private readonly LoggerInterface $fallbackLogger)
    {
        $this->logDirectory = rtrim($logDirectory, '/');

        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0o777, true);
        }
    }

    public function __destruct()
    {
        foreach ($this->fileHandles as $handle) {
            fclose($handle);
        }
    }

    public function log(string $gameId, string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s.u');

        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        if (isset($context['callstack'])) {
            $logEntry['callstack'] = $context['callstack'];
        }

        $formattedEntry = $this->formatLogEntry($logEntry);

        try {
            $this->writeToFile($gameId, $formattedEntry);
        } catch (\Exception $e) {
            $this->fallbackLogger->error('Failed to write to game log file', [
                'gameId' => $gameId,
                'error' => $e->getMessage(),
                'originalMessage' => $message,
            ]);
        }
    }

    private function getLogFilePath(string $gameId): string
    {
        return sprintf('%s/%s.log', $this->logDirectory, $gameId);
    }

    private function formatLogEntry(array $logEntry): string
    {
        $contextData = $logEntry['context'] ?? [];

        if (\is_array($contextData)) {
            unset($contextData['callstack']); // Handle callstack separately

            // Extract key fields for inline display
            $duration = $contextData['duration_ms'] ?? null;
            $resultType = $contextData['result_type'] ?? null;
            unset($contextData['duration_ms'], $contextData['result_type']);
        } else {
            $contextData = [];
            $duration = null;
            $resultType = null;
        }

        // Build inline info
        $inlineInfo = [];
        if ($duration !== null) {
            $inlineInfo[] = "{$duration}ms";
        }
        if ($resultType !== null) {
            $inlineInfo[] = "→ {$resultType}";
        }

        $formatted = sprintf(
            "[%s] %s: %s%s\n",
            (string) ($logEntry['timestamp'] ?? ''),
            strtoupper((string) ($logEntry['level'] ?? 'info')),
            (string) ($logEntry['message'] ?? ''),
            \count($inlineInfo) > 0 ? ' (' . implode(', ', $inlineInfo) . ')' : '',
        );

        // Only show context if there's meaningful data
        if (\count($contextData) > 0) {
            // For simple messages, keep it compact
            if (\count($contextData) === 1 && isset($contextData['message_data'])) {
                $formatted .= '  ' . json_encode($contextData['message_data'], JSON_UNESCAPED_SLASHES) . "\n";
            } else {
                $formatted .= $this->formatData($contextData, 2);
            }
        }

        if (isset($logEntry['callstack']) && \is_array($logEntry['callstack'])) {
            $formatted .= "Call Stack:\n";
            $formatted .= $this->formatCallStack($logEntry['callstack'], 2);
        }

        $formatted .= "\n";

        return $formatted;
    }

    private function formatData(array $data, int $indentLevel = 0): string
    {
        $indent = str_repeat(' ', $indentLevel);
        $formatted = '';

        foreach ($data as $key => $value) {
            if (\is_array($value) || \is_object($value)) {
                $formatted .= sprintf("%s%s:\n", $indent, $key);
                $formatted .= $this->formatData((array) $value, $indentLevel + 2);
            } else {
                $formatted .= sprintf("%s%s: %s\n", $indent, $key, $this->formatValue($value));
            }
        }

        return $formatted;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    private function formatCallStack(array $callStack, int $indentLevel = 0): string
    {
        $indent = str_repeat(' ', $indentLevel);
        $formatted = '';

        foreach ($callStack as $index => $frame) {
            if (!\is_array($frame)) {
                continue;
            }

            $formatted .= sprintf('%s#%d ', $indent, $index);

            if (isset($frame['file'])) {
                $formatted .= sprintf('%s:%d', (string) $frame['file'], (int) ($frame['line'] ?? 0));
            }

            if (isset($frame['class'])) {
                $formatted .= sprintf(
                    ' %s%s%s()',
                    (string) $frame['class'],
                    (string) ($frame['type'] ?? '::'),
                    (string) ($frame['function'] ?? 'unknown'),
                );
            } elseif (isset($frame['function'])) {
                $formatted .= sprintf(' %s()', (string) $frame['function']);
            }

            $formatted .= "\n";
        }

        return $formatted;
    }

    private function writeToFile(string $gameId, string $content): void
    {
        $logFile = $this->getLogFilePath($gameId);

        if (!isset($this->fileHandles[$gameId])) {
            $this->fileHandles[$gameId] = fopen($logFile, 'a');
            if ($this->fileHandles[$gameId] === false) {
                throw new \RuntimeException("Failed to open log file: {$logFile}");
            }
        }

        if (fwrite($this->fileHandles[$gameId], $content) === false) {
            throw new \RuntimeException("Failed to write to log file: {$logFile}");
        }

        fflush($this->fileHandles[$gameId]);
    }
}
