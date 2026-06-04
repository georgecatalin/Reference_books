<?php

declare(strict_types= 1);

function connect(string $server,int $port=1883, int $timeout =30):string
{
    return "Connecting to server $server at port $port with a timeout of {$timeout}s.\n";
}

echo connect("myserver");
echo connect("myserver1", 8883);
echo connect("myserver2",8883, 56);