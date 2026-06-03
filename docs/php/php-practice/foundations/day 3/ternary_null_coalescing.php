<?php

$rssi = -78;

//ternary operator - one line if/else
$signal_strength = $rssi > -70 ? "Excellent":"Bad";
echo "The signal strength is $signal_strength.\n";


//null coalescing in a condition
$timeout = null;
echo "Timeout is " .($timeout ?? 40) ."s\n";

