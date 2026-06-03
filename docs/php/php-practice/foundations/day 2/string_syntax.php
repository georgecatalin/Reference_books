<?php

$protocol = "MQTT";
$port = 1883;

//double quotes -> the variables expand inside
$msgl = "Protocol: $protocol on $port";
echo $msgl ."\n";

//single quotes -> nothing expands faster for static strings
$msg2 = 'Protocol: $protocol on $port';
echo $msg2 ."\n";

//curly braces to isolate a variable from the surrounding text
$type = "tool";
echo "This is a {$type}box\n";

//concatenation with dot operator
$full = "Port: " . $port . " default";
echo $full ."\n";