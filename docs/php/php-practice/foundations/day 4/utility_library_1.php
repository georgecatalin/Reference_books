<?php

declare(strict_types= 1);

function clamp(float $value, float $min, float $max): float
{
    return max($min,min($value, $max));
}

function slugify(string $string): string
{
    $string = strtolower(trim($string));
    $string = preg_replace("/[^a-z0-9]+/","-", $string);

    return trim($string,"-");
}


