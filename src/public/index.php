<?php

declare(strict_types=1);

use App\Infrastructure\Kernel;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return
    /**
     * @param array{APP_ENV: string, APP_DEBUG: string, ...} $context
     */
    static fn(array $context) => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
