<?php

//arithmetic
$a = 47;
$b = 14;

echo $a + $b ."\n";
echo $a - $b ."\n";
echo $a * $b ."\n";
echo $a / $b ."\n";

echo $a % $b ."\n";
echo $a ** $b ."\n";
echo intdiv($a,$b) ."\n";

//assignment shorthands
$x = 14;
$x += 5; //19
$x -= 2; //17
$x *= 2; //34
$x /= 4; //34/4
$x **=2;

$x++; //post increment
++$x; //pre increment

//null coalescing
$config = null;
$timeout = $config ?? 30; //30 if config is null
$timeout ??=47; //assigns 47 only if timeout is null
echo $timeout ."\n"; //30

//spaceship operator returns -1, 0, 1
echo (1 <=>2) ."\n"; //-1
echo (2 <=>2) ."\n"; //0
echo (3 <=>2) ."\n"; //1


