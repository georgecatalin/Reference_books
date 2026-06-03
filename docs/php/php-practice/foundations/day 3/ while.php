<?php

declare(strict_types=1);

$attempts = 0;
$maxRetries = 5;
$connected = false;

while ($attempts < $maxRetries) 
    {
        $attempts++;
        echo "Connection attempt $attempts....\n";

        if($attempts === 3)
            {
                $connected = true;
                echo "The device connected at attempt {$attempts}";
                break;
            }
    }

echo ("\nProgram is completed\n");