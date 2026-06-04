<?php

declare(strict_types= 1);

$thresholdValue = 50.0;

function checkTemperature(float $temperature): string
{
    return $temperature > 50.0 ? "Temperature over limit" : "Temperature ok";

    //$thresholdValue is not accessible in the function without global $thresholdValue
}


function checkTemperatureCorrect(float $temperature, float $threshold): string
{
    return $temperature > $threshold ? "Temperature over limit": "Temperature is fine";
}


echo checkTemperature(20.4) ."\n";
echo checkTemperatureCorrect(67.3, 45.3) ."\n";
