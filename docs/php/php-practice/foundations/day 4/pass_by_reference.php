<?php

declare(strict_types=1);
use const Dom\NO_MODIFICATION_ALLOWED_ERR;

function normalize(float &$voltage,$minimum,$maximum):void
{
    $voltage = ($voltage - $minimum) / ($maximum - $minimum);
}

$voltage = 3.7;
normalize($voltage, 0.0,5.2);
echo "After the normalization, the voltage gets -> $voltage.\n";