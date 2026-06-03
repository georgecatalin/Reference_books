<?php

declare(strict_types=1);

//indexed array - value only
$registers = [0x00, 0x1A, 0xFF, 0x3C];
foreach($registers as $value) 
    {
        printf("Register value: 0x%02X\n",$value);
    }

//indexed array with index
foreach($registers as $index => $value)
    {
        printf("Register[%d] = 0x%02X\n",$index, $value);
    }

//associative array key => value
$config = [
"broker" => "mqtt.factory.local",
"port" => 1883,
"keepalive" => 60,
"clientid" => "vend-001",
];

foreach($config as $key => $value)
    {
        echo "$key => $value \n";
    }