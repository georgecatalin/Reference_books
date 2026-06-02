

You know how to declare variables and understand types. Today you add the ability to make decisions and repeat work — the two constructs that turn a script into a program.

## Conditionals

### if / elseif / else

Nothing surprising here, but PHP has some nuances worth knowing:

```php
<?php
declare(strict_types=1);

$temperature = 87.5;

if ($temperature > 100.0) {
    echo "Critical: thermal shutdown\n";
} elseif ($temperature > 80.0) {
    echo "Warning: approaching limit\n";
} elseif ($temperature > 60.0) {
    echo "Normal operating range\n";
} else {
    echo "Cold start condition\n";
}
```

Note `elseif` is one word in PHP (though `else if` two words also works). Pick one and be consistent — `elseif` is the PHP convention.

### match — the modern switch

PHP 8 introduced `match`, and it's strictly better than `switch` for most cases. Learn it now, don't bother with `switch` habits:

```php
<?php
declare(strict_types=1);

$errorCode = 3;

$message = match($errorCode) {
    0       => "OK",
    1, 2    => "Warning — check sensor",   // multiple conditions on one arm
    3, 4    => "Error — halt operation",
    default => "Unknown error code: $errorCode",
};

echo $message . "\n";
```

Three critical differences from `switch`:

- `match` uses strict comparison (`===`) — no type coercion surprises
- `match` is an expression — it returns a value, so you can assign it directly
- `match` throws an `UnhandledMatchError` if no arm matches and there's no `default` — this is a feature, not a bug. Unmatched cases fail loudly.

```php
<?php
declare(strict_types=1);

$status = "active";

// This would throw UnhandledMatchError if $status isn't one of these:
$label = match($status) {
    "active"   => "Running",
    "idle"     => "Standby",
    "fault"    => "Fault — operator required",
    "shutdown" => "Off",
    default    => "Unknown: $status",
};
```

When you have complex conditions that can't be expressed as simple equality, `match(true)` is the pattern:

```php
<?php
declare(strict_types=1);

$voltage = 4.2;

$state = match(true) {
    $voltage >= 4.2           => "fully charged",
    $voltage >= 3.7           => "normal",
    $voltage >= 3.4           => "low — recharge soon",
    $voltage <  3.4           => "critical — shutting down",
};

echo "Battery: $state\n";
```

## Loops

### for — when you know the count

```php
<?php
declare(strict_types=1);

// Classic indexed loop
for ($i = 0; $i < 8; $i++) {
    echo "Pin $i: " . ($i % 2 === 0 ? "even" : "odd") . "\n";
}

// Countdown
for ($i = 10; $i >= 0; $i--) {
    echo "$i ";
}
echo "\n";

// Step by 2
for ($i = 0; $i <= 16; $i += 2) {
    echo "0x" . str_pad(dechex($i), 2, "0", STR_PAD_LEFT) . " ";
}
echo "\n";
```

### while — when you don't know the count upfront

```php
<?php
declare(strict_types=1);

// Simulating reading bytes until a stop byte (0xFF)
$buffer   = [0x01, 0x02, 0xAB, 0xFF, 0x03];
$position = 0;

while ($position < count($buffer) && $buffer[$position] !== 0xFF) {
    printf("Byte %d: 0x%02X\n", $position, $buffer[$position]);
    $position++;
}
echo "Stop byte found at position $position\n";
```

### do-while — execute at least once, then check

Less common but useful for retry logic and menu systems:

```php
<?php
declare(strict_types=1);

$attempts = 0;
$maxRetries = 3;
$connected = false;

do {
    $attempts++;
    echo "Connection attempt $attempts...\n";
    // Simulate: succeed on attempt 2
    if ($attempts === 2) {
        $connected = true;
    }
} while (!$connected && $attempts < $maxRetries);

echo $connected ? "Connected.\n" : "Failed after $maxRetries attempts.\n";
```

### foreach — the one you'll use most

`foreach` is the idiomatic way to iterate arrays in PHP. You'll write it dozens of times a day:

```php
<?php
declare(strict_types=1);

// Indexed array — value only
$registers = [0x00, 0x1A, 0xFF, 0x3C];
foreach ($registers as $value) {
    printf("Register value: 0x%02X\n", $value);
}

// Indexed array — with index
foreach ($registers as $index => $value) {
    printf("Register[%d] = 0x%02X\n", $index, $value);
}

// Associative array — key => value
$config = [
    "broker"   => "mqtt.factory.local",
    "port"     => 1883,
    "keepalive" => 60,
    "clientId" => "vend-001",
];

foreach ($config as $key => $value) {
    echo "$key: $value\n";
}
```

## break and continue

```php
<?php
declare(strict_types=1);

$packets = [
    ["id" => 1, "valid" => true,  "value" => 42],
    ["id" => 2, "valid" => false, "value" => 0],
    ["id" => 3, "valid" => true,  "value" => 17],
    ["id" => 4, "valid" => true,  "value" => -1],   // sentinel: stop here
    ["id" => 5, "valid" => true,  "value" => 99],
];

foreach ($packets as $packet) {
    if (!$packet["valid"]) {
        continue;   // skip invalid packets, keep going
    }
    if ($packet["value"] === -1) {
        break;      // sentinel value — stop processing entirely
    }
    echo "Packet {$packet['id']}: {$packet['value']}\n";
}
```

`break` and `continue` both accept an integer argument for nested loops — `break 2` exits two levels of loop at once. Use it sparingly; if you need it often, a function is usually the cleaner answer.

## The ternary and null coalescing in conditions

```php
<?php
declare(strict_types=1);

$rssi = -78;

// Ternary — one-line if/else (keep it simple, don't nest these)
$signal = $rssi > -70 ? "good" : "weak";
echo "Signal: $signal\n";

// Null coalescing in a condition
$timeout = null;
echo "Timeout: " . ($timeout ?? 30) . "s\n";

// Nullsafe operator — PHP 8, chains method calls safely
// $device?->getStatus()?->getCode()
// Returns null at the first null instead of throwing an error
```

## Putting it together — a practical example

A packet parser that combines everything from today:

```php
<?php
declare(strict_types=1);

$packets = [
    ["type" => 0x01, "payload" => [0x48, 0x65, 0x6C, 0x6C, 0x6F]],
    ["type" => 0x02, "payload" => [0x00, 0x00, 0x01, 0x00]],
    ["type" => 0x03, "payload" => []],
    ["type" => 0xFF, "payload" => []],   // unknown type
];

foreach ($packets as $packet) {
    $type    = $packet["type"];
    $payload = $packet["payload"];

    $label = match($type) {
        0x01 => "string",
        0x02 => "uint32",
        0x03 => "heartbeat",
        default => "unknown (0x" . dechex($type) . ")",
    };

    if ($label === "heartbeat") {
        echo "Heartbeat received — no payload\n";
        continue;
    }

    if (str_starts_with($label, "unknown")) {
        echo "Dropping unknown packet type: $label\n";
        continue;
    }

    echo "Packet type: $label | Bytes: ";
    foreach ($payload as $i => $byte) {
        printf("0x%02X", $byte);
        echo $i < count($payload) - 1 ? " " : "\n";
    }
}
```

---

### Today's exercise
![[Pasted image 20260602224251.png]]
Part B is the one to prioritize — a typed event dispatcher over an array of structured data is exactly the pattern you'll use when your MQTT broker pushes messages into a PHP backend. The `match` arms map cleanly to topic handlers. Build it with your actual vending machine event types if you want extra relevance.


Paste your code when you're ready and we'll move to Day 4 — functions.

[[Day 2 — Variables, types & operators]]