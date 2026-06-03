<?php

declare(strict_types=1);

//classic indexed loop
for ($i = 0; $i < 20 ; $i++) 
    {
        echo "Pin $i: " . ($i % 2 == 0 ? "even" : "odd") ."\n";
    }

//countdown
for ($i = 100;$i>=0;$i--)
    {
        echo "$i ";
    }

echo "\n";

//step by 2
for($i = 0; $i<20; $i+=2)
    {
        echo "$i " ."\n";
    }
echo "\n";