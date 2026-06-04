<?php

declare(strict_types=1);

function makeThresholdChecker(float $limit): callable
{
    return function (float $value) use ($limit) : bool
    {
        return $value >= $limit;
    };
}

$isCritical = makeThresholdChecker(90.0);
$isWarning = makeThresholdChecker(75.0);


$readings = [65.0,78.0,91.0,83.0];


foreach ($readings as $read) 
    {
        $status = match(true)
        {
            $isCritical($read) => "CRITICAL",
            $isWarning($read) => "WARNING",
            default => "OK",
        };

        echo("$read -> $status\n");
    }