<?php

$input = 3.7;

//casting - explicit conversion
$integer = (int) $input;
$float = (float) $input;
$bool = (bool) $input;
$string = (string) $input;

//type checking functions
var_dump(is_int($integer));
var_dump(is_float($float));
var_dump(is_bool($bool));
var_dump(is_string($string));
var_dump(is_null(null));

//gettype returns a string value
echo gettype($integer) ."\n";