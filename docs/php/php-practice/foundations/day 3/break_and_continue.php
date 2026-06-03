<?php

declare(strict_types=1);

$packets = [
["id" => 1, "valid" => true, "value" => 42],
["id"=>2, "valid" => false, "value" => 0],
["id"=>3, "valid" => true, "value"=> 1978],
["id"=>4, "valid"=> true, "value" => -1], //sentinel - stop here
["id"=>5, "valid" =>true, "value"=>2011],
];

foreach ($packets as $packet) 
    {
        //skip the invalid packets
        if(!$packet["valid"])
            {
                continue;
            }

        //make use of the sentinel value
        if($packet["value"] === -1)
            {
                break; //sentinel value reached, stop processing entirely
            }

        echo "Packet received is {$packet["id"]} and holds the value {$packet["value"]} \n";
    }