<?php

declare(strict_types= 1);

//void -> the function produces only side effects, does not return anything
function logEvent(string $event):void
{
    echo "[" . date("H:i:s") ."] $event\n";
}

//nullable -> function can return a type or null
function findDevice(string $device) : ?string
{
    $devices = [ "vend-001" => "192.169.10.1", "vend-002" => "192.169.10.2"];
    return $devices[$device] ?? null;
}

$ip  = findDevice("vend-001");
if($ip !== null)
    {
        echo "The device ip is $ip.\n";
    }

//union types - PHP 8 can return one or more types
function parseValue(string $input): int|float|bool
{
    if($input === "true") return true;
    if($input === "false") return false;

    if(str_contains($input,".")) return (float) $input;

    return (int) $input;

}

var_dump(parseValue("47"));
var_dump(parseValue("true"));
var_dump(parseValue("3.14"));