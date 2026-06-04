<?php

declare(strict_types=1);

//variadic functions are functions that accept a variable number of arguments

function average(float ...$values):float
{
 if(count($values) === 0)
    {
        return 0.0;
    }

  return array_sum($values) / count($values);
}

echo average(1977, 1978, 2011) ."\n";

//using the splat operator to unpack an array into arguments
$this_array = [14,47,48,73,72];
echo average(...$this_array) ."\n";
