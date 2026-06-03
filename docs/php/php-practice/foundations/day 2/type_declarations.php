<?php

declare(strict_types= 1); // enables strict function parameters moe

function celsiusToFahrenheit(float $celsius): float
{
    return ($celsius * 9/5) + 32;
}

echo celsiusToFahrenheit(100.0) ."\n";
echo celsiusToFahrenheit(0.0) ."\n";