

Arrays are PHP's most versatile data structure. Unlike C arrays — fixed type, fixed size, contiguous memory — a PHP array is simultaneously a list, a dictionary, a stack, a queue, and an ordered map. One type does the work of five. Understanding arrays deeply is what separates PHP beginners from PHP developers.

## Two flavors, one type

```php
<?php
declare(strict_types=1);

// Indexed array — integer keys, auto-assigned from 0
$registers = [0x00, 0x1A, 0xFF, 0x3C];

// Associative array — string keys, you define them
$config = [
    "broker"    => "mqtt.factory.local",
    "port"      => 1883,
    "keepalive" => 60,
];

// They are the same underlying type — you can mix keys
$mixed = [
    0       => "first",
    "label" => "second",
    1       => "third",
];
```

Under the hood PHP uses a single ordered hash map for all of them. The "indexed" array is just an associative array whose keys happen to be sequential integers. This matters when you start manipulating arrays — operations like `array_splice` or `unset` can leave gaps in integer keys that surprise you later.

## Reading and writing

```php
<?php
declare(strict_types=1);

$devices = ["vend-001", "vend-002", "vend-003"];

// Read by index
echo $devices[0] . "\n";   // vend-001

// Append — empty brackets always appends
$devices[] = "vend-004";

// Modify in place
$devices[1] = "vend-002-replaced";

// Remove — note: leaves a gap in keys
unset($devices[2]);
var_dump($devices);
// array(3) { [0]=>"vend-001" [1]=>"vend-002-replaced" [3]=>"vend-004" }
// Key 2 is gone. Key 3 still exists. This is a common gotcha.

// Re-index after unset
$devices = array_values($devices);
var_dump($devices);
// Now 0, 1, 2 — sequential again
```

```php
<?php
declare(strict_types=1);

$config = ["broker" => "mqtt.factory.local", "port" => 1883];

// Read — use ?? for safe access when key might not exist
$tls = $config["tls"] ?? false;

// Check existence without triggering a notice
if (array_key_exists("port", $config)) {
    echo "Port: {$config['port']}\n";
}

// isset() also works but returns false for null values — know the difference
isset($config["broker"]);        // true
isset($config["missing"]);       // false
array_key_exists("missing", $config); // false — same here, but true if value is null
```

## Multidimensional arrays

Real data is nested. Get comfortable reading and writing multiple levels deep:

```php
<?php
declare(strict_types=1);

$fleet = [
    [
        "id"        => "vend-001",
        "location"  => "Floor 1",
        "status"    => "online",
        "inventory" => ["slots" => 20, "filled" => 17],
    ],
    [
        "id"        => "vend-002",
        "location"  => "Floor 2",
        "status"    => "fault",
        "inventory" => ["slots" => 20, "filled" => 3],
    ],
    [
        "id"        => "vend-003",
        "location"  => "Lobby",
        "status"    => "online",
        "inventory" => ["slots" => 15, "filled" => 15],
    ],
];

// Read nested value
echo $fleet[0]["inventory"]["filled"] . "\n";   // 17

// Iterate and extract
foreach ($fleet as $machine) {
    $pct = round($machine["inventory"]["filled"] / $machine["inventory"]["slots"] * 100);
    echo "{$machine['id']} ({$machine['location']}): {$machine['status']} — {$pct}% full\n";
}
```

## The four functions you'll use every day

### array_map — transform every element

```php
<?php
declare(strict_types=1);

$voltages = [3.3, 5.0, 12.0, 24.0];

// Apply a function to every element, return a new array
$millivolts = array_map(fn(float $v): float => $v * 1000.0, $voltages);
var_dump($millivolts);   // [3300.0, 5000.0, 12000.0, 24000.0]

// Map over an associative array — keys are preserved
$config = ["timeout" => 30, "retries" => 3, "interval" => 5];
$doubled = array_map(fn(int $v): int => $v * 2, $config);
var_dump($doubled);   // ["timeout"=>60, "retries"=>6, "interval"=>10]

// Map two arrays in parallel — pass both, no callback key
$labels = ["temp", "voltage", "current"];
$values = [87.5, 3.3, 1.2];
$pairs  = array_map(null, $labels, $values);
// [[temp, 87.5], [voltage, 3.3], [current, 1.2]]
```

### array_filter — keep elements that pass a test

```php
<?php
declare(strict_types=1);

$readings = [
    ["sensor" => "temp",    "value" => 87.5, "valid" => true],
    ["sensor" => "voltage", "value" => -1.0, "valid" => false],
    ["sensor" => "current", "value" => 1.2,  "valid" => true],
    ["sensor" => "rssi",    "value" => -95.0,"valid" => false],
];

// Keep only valid readings
$valid = array_filter($readings, fn(array $r): bool => $r["valid"]);

// array_filter preserves keys — use array_values if you need 0-based index
$valid = array_values($valid);

foreach ($valid as $r) {
    echo "{$r['sensor']}: {$r['value']}\n";
}

// Filter without a callback — removes all falsy values (null, false, 0, "", [])
$sparse = [1, 0, null, "ok", false, "", 42];
$clean  = array_values(array_filter($sparse));
var_dump($clean);   // [1, "ok", 42]
```

### array_reduce — collapse an array to a single value

```php
<?php
declare(strict_types=1);

$transactions = [
    ["item" => "Drill bit",   "qty" => 2, "price" => 4.50],
    ["item" => "Safety glove","qty" => 4, "price" => 2.25],
    ["item" => "Cable tie",   "qty" => 10,"price" => 0.30],
];

// Sum the total cost
$total = array_reduce(
    $transactions,
    fn(float $carry, array $t): float => $carry + ($t["qty"] * $t["price"]),
    0.0    // initial value
);

echo "Total: $" . number_format($total, 2) . "\n";   // Total: $21.00

// Build an associative array from a list
$byItem = array_reduce(
    $transactions,
    function(array $carry, array $t): array {
        $carry[$t["item"]] = $t["qty"] * $t["price"];
        return $carry;
    },
    []
);

var_dump($byItem);
// ["Drill bit" => 9.0, "Safety glove" => 9.0, "Cable tie" => 3.0]
```

### array_column — extract a column from a 2D array

```php
<?php
declare(strict_types=1);

$fleet = [
    ["id" => "vend-001", "status" => "online",  "floor" => 1],
    ["id" => "vend-002", "status" => "fault",   "floor" => 2],
    ["id" => "vend-003", "status" => "online",  "floor" => 1],
];

// Extract one column
$ids      = array_column($fleet, "id");      // ["vend-001","vend-002","vend-003"]
$statuses = array_column($fleet, "status");  // ["online","fault","online"]

// Index result by a key — incredibly useful
$byId = array_column($fleet, null, "id");
// Now: $byId["vend-002"]["status"] === "fault"
echo $byId["vend-002"]["status"] . "\n";   // fault
```

`array_column` with `null` as the second argument re-indexes the whole array by a key. This is a one-liner that replaces a foreach loop you'd otherwise write constantly.

## Sorting

```php
<?php
declare(strict_types=1);

// sort — sorts indexed array in place, re-indexes keys
$temps = [87.5, 23.1, 65.0, 44.8];
sort($temps);
var_dump($temps);   // [23.1, 44.8, 65.0, 87.5]

// rsort — reverse sort
rsort($temps);

// asort — sort by value, preserve keys (use for associative arrays)
$scores = ["vend-001" => 87, "vend-002" => 43, "vend-003" => 95];
asort($scores);   // sorted by value, keys intact

// ksort — sort by key
ksort($scores);

// usort — sort by custom comparison (the one you'll reach for most)
$fleet = [
    ["id" => "vend-003", "fill_pct" => 100],
    ["id" => "vend-001", "fill_pct" => 85],
    ["id" => "vend-002", "fill_pct" => 15],
];

// Sort by fill_pct ascending — lowest stock first (needs restocking)
usort($fleet, fn(array $a, array $b): int => $a["fill_pct"] <=> $b["fill_pct"]);

foreach ($fleet as $m) {
    echo "{$m['id']}: {$m['fill_pct']}%\n";
}
// vend-002: 15%
// vend-001: 85%
// vend-003: 100%
```

The spaceship operator `<=>` is made for `usort`. It returns -1, 0, or 1 — exactly what the sort callback needs. Reverse the operands for descending order.

## Stack and queue operations

PHP arrays double as stacks and queues with four built-in functions:

```php
<?php
declare(strict_types=1);

$queue = [];

// Queue (FIFO) — enqueue at end, dequeue from front
array_push($queue, "job-1");    // or $queue[] = "job-1"
array_push($queue, "job-2");
array_push($queue, "job-3");
$next = array_shift($queue);    // "job-1" — removes from front
echo $next . "\n";

// Stack (LIFO) — push and pop from end
$stack = [];
array_push($stack, "frame-A");
array_push($stack, "frame-B");
$top = array_pop($stack);       // "frame-B"
echo $top . "\n";

// array_unshift — prepend to front (expensive on large arrays)
array_unshift($queue, "priority-job");
```

## Spread, merge, and destructure

```php
<?php
declare(strict_types=1);

$defaults = ["timeout" => 30, "retries" => 3, "tls" => false];
$overrides = ["timeout" => 10, "tls" => true];

// array_merge — later keys overwrite earlier keys
$config = array_merge($defaults, $overrides);
// ["timeout"=>10, "retries"=>3, "tls"=>true]

// Spread operator — same effect, more readable in PHP 8.1+
$config = [...$defaults, ...$overrides];

// Array destructuring with list() / short syntax
$coords = [48.8566, 2.3522];
[$lat, $lng] = $coords;
echo "Lat: $lat, Lng: $lng\n";

// Destructure with keys
$machine = ["id" => "vend-001", "status" => "online", "floor" => 2];
["id" => $id, "floor" => $floor] = $machine;
echo "$id is on floor $floor\n";

// Skip elements with a blank
[, $second, , $fourth] = [10, 20, 30, 40];
echo "$second $fourth\n";   // 20 40
```

---

## Today's exercise

![[Pasted image 20260602224903.png]]
Part A is the one to nail — the map → filter → sort pipeline is the most common array processing pattern in real PHP backends. You've already got a fleet data model from your MQTT architecture work, so use real machine ids and realistic inventory numbers. Make the restock report output something you could actually hand to a warehouse operator.


Paste your code when you're done and we'll move to Day 6 — strings and dates.