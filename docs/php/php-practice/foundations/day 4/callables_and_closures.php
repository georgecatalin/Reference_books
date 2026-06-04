<?php


declare(strict_types=1);

//anonymous functions (closures) stored in a variable
$double = function(float $x): float
{
    return $x *2.0;
};

echo $double(3.4) ."\n";


//arrow function 
$factor = 1.0;
$scale = fn(float $x): float => $x * $factor;

echo $scale(5.3) ."\n";


//passing a function as an argument
function applyToAll(array $values, callable $fn): array 
{
    return array_map($fn, $values);
} 


$voltages = [3.4,5.0,12.3,34.2];

$millivolts = applyToAll($voltages, fn(float $value):float => $value * 1000.0);

foreach($millivolts as $milli) 
    {
        echo "$milli mV \n";
    }