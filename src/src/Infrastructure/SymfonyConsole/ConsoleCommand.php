<?php

declare(strict_types=1);

namespace App\Infrastructure\SymfonyConsole;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @codeCoverageIgnore
 */
abstract class ConsoleCommand extends Command
{
    final protected function interact(InputInterface $input, OutputInterface $output): void
    {
        \assert($output instanceof ConsoleOutputInterface);

        $this->doInteract($input, new Output($input, $output));
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        \assert($output instanceof ConsoleOutputInterface);

        return $this->doExecute($input, new Output($input, $output));
    }

    protected function doInteract(InputInterface $input, Output $output): void {}

    abstract protected function doExecute(InputInterface $input, Output $output): int;
}
