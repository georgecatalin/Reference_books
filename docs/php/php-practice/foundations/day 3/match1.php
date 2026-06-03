<?php

declare(strict_types=1);

$voltage = 4.2;

$state = match(true)  //When you want to evaluate conditional ranges (like >= or <), you need to pass true as the subject of the match statement. This tells PHP: "Find the first line that evaluates to true."
{
    $voltage >= 4.2 => "fully charged",
    $voltage >= 3.7 => "normal",
    $voltage >= 3.4 => "low - recharge soon",
    $voltage < 3.4 => "critical - shutting down",
};

echo "the state is {$state} \n";