<?php

$title = "My PHP Page";
$items = ["Resistor","capacitor","microcontroller","mosfet"];
$now = date("Y-m-d H:i:s");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1><?= $title ?></h1>
    <p>Server time: <?= $now ?></p>

    <h2>Components</h2>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $item ?></li>
        <?php endforeach; ?>
    </ul>
 </body>
 </html>