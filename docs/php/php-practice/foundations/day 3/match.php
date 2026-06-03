<?php

declare(strict_types= 1);

$errorCode = 3;

$message = match ($errorCode) 
{
    0 => "OK",
    1,2 => "Warning - check the sensor",
    3,4 => "Error - halt operation",
    default => "unknown error code : $errorCode",
};

echo $message ."\n";