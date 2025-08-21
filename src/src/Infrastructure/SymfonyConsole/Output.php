<?php

declare(strict_types=1);

namespace App\Infrastructure\SymfonyConsole;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarDumper\VarDumper;

/**
 * @codeCoverageIgnore
 */
final readonly class Output
{
    private ConsoleOutputInterface $output;

    private SymfonyStyle $io;

    public function __construct(
        InputInterface $input,
        OutputInterface $output,
    ) {
        \assert($output instanceof ConsoleOutputInterface);
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
    }

    public function newLine(int $count = 1): void
    {
        $this->io->newLine($count);
    }

    /**
     * @param string|array<string> $messages
     */
    public function writeln(array|string $messages): void
    {
        $this->io->writeln($messages);
    }

    public function json(mixed $value, int $flags = 0): void
    {
        $this->io->writeln(jsonEncode($value, $flags));
    }

    public function title(string $message): void
    {
        $this->io->title($message);
    }

    public function section(): ConsoleSectionOutput
    {
        return $this->output->section();
    }

    /**
     * @param string|array<string> $message
     */
    public function text(array|string $message): void
    {
        $this->io->text($message);
    }

    /**
     * @param string|array<string> $message
     */
    public function comment(array|string $message): void
    {
        $this->io->comment($message);
    }

    /**
     * @param string|array<string> $message
     */
    public function success(array|string $message): void
    {
        $this->io->success($message);
    }

    /**
     * @param string|array<string> $message
     */
    public function error(array|string $message): void
    {
        $this->io->error($message);
    }

    /**
     * @param string|array<string> $message
     */
    public function warning(array|string $message): void
    {
        $this->io->warning($message);
    }

    /**
     * @param string|array<string> $message
     */
    public function note(array|string $message): void
    {
        $this->io->note($message);
    }

    public function isQuiet(): bool
    {
        return $this->io->isQuiet();
    }

    /**
     * @param string|array<string> $message
     */
    public function info(array|string $message): void
    {
        $this->io->info($message);
    }

    /**
     * @param array<string>                          $headers
     * @param iterable<array<string>|TableSeparator> $rows
     */
    public function table(array $headers, iterable $rows = []): Table
    {
        $table = new Table($this->io);
        $table->setHeaders($headers);

        foreach ($rows as $row) {
            $table->addRow($row);
        }

        $table->render();

        return $table;
    }

    public function ask(string $question, ?string $default = null, ?callable $validator = null): mixed
    {
        return $this->io->ask($question, $default, $validator);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        return $this->io->confirm($question, $default);
    }

    public function choice(string $question, array $choices, null|int|string $default = null): mixed
    {
        return $this->io->choice($question, $choices, $default);
    }

    public function askQuestion(Question $question): mixed
    {
        return $this->io->askQuestion($question);
    }

    public function dump(mixed $value): void
    {
        if (!$this->isQuiet()) {
            VarDumper::dump($value);
        }
    }

    public function progressBar(string $operations, int $max = 0): ProgressBar
    {
        $progressBar = $this->io->createProgressBar($max);
        $progressBar->setFormat(match (true) {
            $max > 0 => " %current%/%max% {$operations} [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%",
            default => " %current% {$operations} [%bar%] %elapsed:6s% %memory:6s%",
        });

        return $progressBar;
    }
}
