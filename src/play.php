<?php

require __DIR__ . '/vendor/autoload.php';

foreach (\App\Game\Field\TileSide::getSidesStartingFrom(\App\Game\Field\TileSide::RIGHT) as $side) {
    echo $side->value . PHP_EOL;
}