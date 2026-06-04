<?php

declare(strict_types=1);

require_once 'utility_library_1.php';

echo clamp(150.0, 0.0, 100.0) ."\n";
echo slugify("Vending machine #3 (Floor 2) \n");