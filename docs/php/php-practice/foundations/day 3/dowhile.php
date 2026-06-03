<?php

declare(strict_types=1);

$attempts = 0;
$maxRetries = 3;
$connected = false;

do
{
    $attempts++;
    echo "Connection attempt ...{$attempts}\n";
    sleep(1);
    if($attempts === 2)
        {
            $connected = true;
            echo  "The device connected the attempt {$attempts}\n";
            break;
        }
} while($attempts < $maxRetries);

echo "\nOver and out\n";