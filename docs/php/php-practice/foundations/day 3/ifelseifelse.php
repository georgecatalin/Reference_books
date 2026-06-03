<?php
declare(strict_types= 1);

$temperature =38.1;

if($temperature >40)
    {
        echo "Critical: thermal shutdown. \n";
    }
elseif($temperature > 35)
    {
        echo "Warning: approaching limit...\n";
    }
elseif($temperature > 20)
    {
        echo "Normal operating range...\n";
    }
else
    {
        echo "Cold starting condition";
    }
