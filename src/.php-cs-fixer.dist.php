<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PHPyh\CodingStandard\PhpCsFixerCodingStandard;

$finder = (new Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->append([
        __FILE__,
        __DIR__ . '/bin/console',
    ]);

$config = (new Config())
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->setFinder($finder);

(new PhpCsFixerCodingStandard())->applyTo($config, [
    //    'final_class' => false,
    //    'final_public_method_for_abstract_class' => false,
]);

return $config;
