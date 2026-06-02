

Strings in PHP are byte strings — not unicode-aware by default. Dates are notoriously tricky in every language. Today you build solid habits with both before they cause you problems in real projects.

## Strings — the functions you'll actually use

PHP has over 100 string functions. Here are the ones that appear in real codebases constantly. Learn these cold — everything else you look up as needed.

### Testing and searching

```php
<?php
declare(strict_types=1);

$topic = "factory/floor-2/vend-001/status";

// PHP 8 — the readable way (use these, not strpos tricks)
var_dump(str_contains($topic, "vend-001"));     // true
var_dump(str_starts_with($topic, "factory"));   // true
var_dump(str_ends_with($topic, "status"));      // true

// Before PHP 8 you had to use strpos, which returns int|false
// strpos returns 0 (falsy!) when the needle is at position 0 — classic bug
$pos = strpos($topic, "factory");
var_dump($pos);           // int(0)
var_dump($pos === false); // false — it WAS found, at position 0

// The old bug: if (strpos($topic, "factory")) — evaluates to 0 = falsy = wrong
// The fix:     if (strpos($topic, "factory") !== false)
// PHP 8 str_contains avoids this entirely — use it
```

### Transforming

```php
<?php
declare(strict_types=1);

$raw = "  Vending Machine #3 (FLOOR 2)  ";

echo strtolower($raw)  . "\n";   // "  vending machine #3 (floor 2)  "
echo strtoupper($raw)  . "\n";   // "  VENDING MACHINE #3 (FLOOR 2)  "
echo trim($raw)        . "\n";   // "Vending Machine #3 (FLOOR 2)"
echo ltrim($raw)       . "\n";   // left trim only
echo rtrim($raw)       . "\n";   // right trim only

// ucfirst, ucwords
echo ucfirst("hello world") . "\n";   // "Hello world"
echo ucwords("hello world") . "\n";   // "Hello World"

// str_replace — replaces all occurrences
$topic  = "factory/floor-1/vend-001/status";
$newTopic = str_replace("floor-1", "floor-2", $topic);
echo $newTopic . "\n";   // factory/floor-2/vend-001/status

// Replace multiple things at once — arrays work
$clean = str_replace(["#", "(", ")", " "], ["-", "", "", "-"], "Vend #3 (Floor 2)");
echo $clean . "\n";   // Vend--3-Floor-2-

// str_pad — fixed-width output, useful for aligned tables
echo str_pad("vend-001", 12) . "online\n";    // right-padded
echo str_pad("87.5°C", 10, " ", STR_PAD_LEFT) . "\n";  // left-padded
```

### Splitting and joining

```php
<?php
declare(strict_types=1);

// explode — split string into array
$topic  = "factory/floor-2/vend-001/status";
$parts  = explode("/", $topic);
var_dump($parts);
// ["factory", "floor-2", "vend-001", "status"]

// Limit argument — split into at most N pieces
$parts2 = explode("/", $topic, 3);
var_dump($parts2);
// ["factory", "floor-2", "vend-001/status"]  ← last piece is the remainder

// implode (alias: join) — array back to string
$rebuilt = implode("/", $parts);
echo $rebuilt . "\n";   // factory/floor-2/vend-001/status

// Practical: parse an MQTT topic into components
function parseTopic(string $topic): array {
    $parts = explode("/", $topic);
    return [
        "site"      => $parts[0] ?? null,
        "zone"      => $parts[1] ?? null,
        "device"    => $parts[2] ?? null,
        "channel"   => $parts[3] ?? null,
    ];
}

var_dump(parseTopic("factory/floor-2/vend-001/telemetry"));
```

### Formatting output — sprintf and printf

`sprintf` is one of the most useful functions in PHP. Learn the format specifiers — they're the same as C's `printf`:

```php
<?php
declare(strict_types=1);

$id       = "vend-001";
$temp     = 87.532;
$voltage  = 3.3;
$bytes    = [0x01, 0xAB, 0xFF];

// %s = string, %d = integer, %f = float, %x = hex
echo sprintf("Device: %s | Temp: %.1f°C | Voltage: %.2fV\n", $id, $temp, $voltage);
// Device: vend-001 | Temp: 87.5°C | Voltage: 3.30V

// Padding with sprintf
echo sprintf("%-12s %6s %8s\n", "Device", "Status", "Fill");
echo sprintf("%-12s %6s %7d%%\n", "vend-001", "online", 85);
echo sprintf("%-12s %6s %7d%%\n", "vend-002", "fault",  12);
// %-12s = left-align in 12 chars, %6s = right-align in 6 chars

// Hex formatting — you know this one from embedded work
foreach ($bytes as $b) {
    echo sprintf("0x%02X ", $b);   // 0x01 0xAB 0xFF
}
echo "\n";

// number_format — human-readable numbers
echo number_format(1234567.891, 2, ".", ",") . "\n";  // 1,234,567.89
echo number_format(1234567.891, 2, ",", ".") . "\n";  // 1.234.567,89 (European)
```

### Substrings

```php
<?php
declare(strict_types=1);

$id = "vend-001";

echo substr($id, 0, 4)  . "\n";   // "vend"       — first 4 chars
echo substr($id, 5)     . "\n";   // "001"         — from position 5 to end
echo substr($id, -3)    . "\n";   // "001"         — last 3 chars

echo strlen($id) . "\n";          // 8

// substr_count — count occurrences
$log = "ERROR: disk ERROR: timeout ERROR: memory";
echo substr_count($log, "ERROR") . "\n";   // 3

// str_repeat
echo str_repeat("-", 40) . "\n";   // ----------------------------------------
```

### Regular expressions — the basics

Use `preg_` functions for patterns that `str_` functions can't handle. Don't overuse regex — a specific string function is always faster and clearer when it fits:

```php
<?php
declare(strict_types=1);

$input = "Device vend-042 reported 3 faults at 14:32:07";

// preg_match — find first match, returns 1 (found) or 0 (not found)
if (preg_match('/vend-(\d+)/', $input, $matches)) {
    echo "Device number: " . $matches[1] . "\n";   // 042
}

// preg_match_all — find all matches
$data = "temp=87.5 voltage=3.3 current=1.2";
preg_match_all('/(\w+)=([\d.]+)/', $data, $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    echo "{$m[1]}: {$m[2]}\n";
}

// preg_replace — replace by pattern
$messy = "vend_001--status__update";
$clean = preg_replace('/[_-]+/', '-', $messy);
echo $clean . "\n";   // vend-001-status-update

// preg_split — split by pattern
$parts = preg_split('/[\s,;]+/', "alpha, beta;  gamma delta");
var_dump($parts);   // ["alpha", "beta", "gamma", "delta"]
```

## Dates and times

PHP's date handling has two eras: the old procedural `date()`/`time()` functions, and the modern OOP `DateTime`/`DateTimeImmutable` classes. Use the OOP API — it's safer, more expressive, and handles timezones correctly.

### The basics

```php
<?php
declare(strict_types=1);

// Always set your timezone — never rely on the default
date_default_timezone_set("UTC");

// Unix timestamp — seconds since 1970-01-01 00:00:00 UTC
$now = time();
echo $now . "\n";   // e.g. 1717286400

// Procedural formatting — fine for simple cases
echo date("Y-m-d")           . "\n";   // 2026-06-02
echo date("H:i:s")           . "\n";   // 14:32:07
echo date("Y-m-d H:i:s")     . "\n";   // 2026-06-02 14:32:07
echo date("D, d M Y")        . "\n";   // Tue, 02 Jun 2026
echo date("U")               . "\n";   // unix timestamp as string
```

### DateTime — the OOP way

```php
<?php
declare(strict_types=1);

date_default_timezone_set("UTC");

// Create from now
$now = new DateTimeImmutable();

// Create from a string
$event = new DateTimeImmutable("2026-06-15 09:00:00");

// Create from a format (when the string isn't standard)
$parsed = DateTimeImmutable::createFromFormat("d/m/Y H:i", "15/06/2026 09:00");

// Format output
echo $now->format("Y-m-d H:i:s") . "\n";
echo $now->format("U") . "\n";   // unix timestamp

// Modify — immutable means you get a NEW object, original unchanged
$tomorrow    = $now->modify("+1 day");
$nextWeek    = $now->modify("+7 days");
$inTwoHours  = $now->modify("+2 hours");
$startOfDay  = $now->setTime(0, 0, 0);

echo $tomorrow->format("Y-m-d") . "\n";
echo $now->format("Y-m-d")      . "\n";   // unchanged — immutable
```

`DateTimeImmutable` vs `DateTime`: always use `DateTimeImmutable`. The mutable `DateTime` modifies the object in place, which causes subtle bugs when you pass dates into functions expecting them not to change. Immutable is the correct default — exactly like `const` in C for parameters you don't intend to modify.

### DateInterval — differences and durations

```php
<?php
declare(strict_types=1);

date_default_timezone_set("UTC");

$installed  = new DateTimeImmutable("2025-01-15 08:00:00");
$now        = new DateTimeImmutable("2026-06-02 14:32:00");

// diff returns a DateInterval
$age = $installed->diff($now);

echo sprintf(
    "Machine age: %d years, %d months, %d days\n",
    $age->y, $age->m, $age->d
);

// Total days — use the days property (not d — that's just the day component)
echo "Total days in service: " . $age->days . "\n";

// Create an interval directly
$maintenance = new DateInterval("P30D");   // P = period, 30D = 30 days
$nextService = $now->add($maintenance);
echo "Next service due: " . $nextService->format("Y-m-d") . "\n";

// Subtract
$lastService = $now->sub(new DateInterval("P14D"));
echo "Last service was: " . $lastService->format("Y-m-d") . "\n";
```

### Practical patterns

```php
<?php
declare(strict_types=1);

date_default_timezone_set("UTC");

// "Time ago" — the pattern used in every feed and notification UI
function timeAgo(DateTimeImmutable $past): string {
    $diff    = $past->diff(new DateTimeImmutable());
    $seconds = (int)(new DateTimeImmutable())->format("U") - (int)$past->format("U");

    return match(true) {
        $seconds < 60      => "just now",
        $seconds < 3600    => $diff->i . "m ago",
        $seconds < 86400   => $diff->h . "h ago",
        $diff->days < 7    => $diff->days . "d ago",
        $diff->days < 30   => (int)($diff->days / 7) . "w ago",
        $diff->m < 12      => $diff->m . " months ago",
        default            => $diff->y . " years ago",
    };
}

$events = [
    new DateTimeImmutable("-45 seconds"),
    new DateTimeImmutable("-20 minutes"),
    new DateTimeImmutable("-3 hours"),
    new DateTimeImmutable("-2 days"),
    new DateTimeImmutable("-3 weeks"),
];

foreach ($events as $e) {
    echo $e->format("Y-m-d H:i:s") . " → " . timeAgo($e) . "\n";
}

// Timestamp round-trip — store as UTC, display in local time
function storeEvent(string $description): array {
    return [
        "description" => $description,
        "created_at"  => (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("Y-m-d H:i:s"),
    ];
}

function displayEvent(array $event, string $timezone = "Europe/Bucharest"): string {
    $utc   = new DateTimeImmutable($event["created_at"], new DateTimeZone("UTC"));
    $local = $utc->setTimezone(new DateTimeZone($timezone));
    return "[{$local->format('Y-m-d H:i')}] {$event['description']}";
}

$e = storeEvent("Machine vend-001 came online");
echo displayEvent($e) . "\n";   // shown in your local timezone
```

Store timestamps as UTC, convert to local time only at display. This is the universal rule — violating it causes phantom date bugs the moment your app runs across timezone boundaries.

---

## Today's exercise



![[Pasted image 20260602225422.png]]
Part A ties directly into your MQTT architecture — topic parsing and building is something a real fleet backend does on every incoming message. Part B gives you the `timeAgo` function that every dashboard UI needs. The stretch goal is the one with the most real-world payoff: once you understand UTC storage + local display, you never have timezone bugs again.


Paste your code when done. With Days 6 and 7 both complete you'll have finished Phase 1 — Day 8 (error handling) is the last piece before we move into HTTP and databases.