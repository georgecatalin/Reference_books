<?php

declare(strict_types=1);

$packets = [
    ["type" =>0x01, "payload" => [0x48,0x65,0x6C,0x6C,0x6F]],
    ["type" =>0x02, "payload" => [0x00, 0x00,0x01,0x00]],
    ["type" =>0x03, "payload" =>[]],
    ["type"=>0xFF, "payload"=>[]], //unknown type
];

foreach ($packets as $packet) 
    {
        $type = $packet["type"];
        $data = $packet["payload"];

        $label = match($type)
        {
            0x01 => "string",
            0x02 => "uint32",
            0x03 => "heartbeat",
            default => "unknown type",
        };

        if($label === "heartbeat")
            {
                echo "Heartbeat received -no payload\n";
                continue;
            }

        if(str_starts_with($label,"unknown"))
            {
                echo "Dropping unknown packet type: $label\n";
                continue;
            }

        echo "Packet type: $label | Bytes:";
        foreach($data as $i =>$byte)
            {
                printf("0x%02X", $byte);
                echo $i < count($data) -1?"":"\n";
            }
    }