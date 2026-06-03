<?php

$integer = 47;
$float = 3.14;
$string = "embedded systems";
$bool = true;
$nothing = null;

echo gettype($integer) ."\n";
echo gettype($float) ."\n";
echo gettype($string) ."\n";
echo gettype($bool) ."\n";
echo gettype($nothing) ."\n";

var_dump($integer);
var_dump($float);
var_dump($bool);
var_dump($nothing);
var_dump($string);
