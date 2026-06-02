

Variables hold data. Control flow directs execution. Functions are how you package logic so it can be named, reused, and tested. Everything you write from Day 5 onward will be organized into functions — get the fundamentals solid today.

## The anatomy of a PHP function

```php
<?php
declare(strict_types=1);

function greet(string $name): string {
    return "Hello, $name!";
}

echo greet("George") . "\n";   // Hello, George!
```

Five parts to notice: the `function` keyword, the name, the parameter list with type hints, the return type after `:`, and the `return` statement. With `declare(strict_types=1)` active, PHP enforces the types at call time — passing the wrong type throws a `TypeError` immediately rather than silently coercing.

## Parameters — defaults, references, variadic

### Default parameter values

```php
<?php
declare(strict_types=1);

function connect(string $host, int $port = 1883, int $timeout = 30): string {
    return "Connecting to $host:$port with {$timeout}s timeout";
}

echo connect("mqtt.factory.local") . "\n";           // uses both defaults
echo connect("mqtt.factory.local", 8883) . "\n";     // overrides port only
echo connect("mqtt.factory.local", 8883, 10) . "\n"; // overrides both
```

Required parameters must come before optional ones — PHP will error if you put a default parameter before a required one. The compiler catches this, same as C function signature rules.

### Pass by reference

By default PHP passes by value — the function gets a copy. Use `&` to pass by reference when you need the function to modify the original:

```php
<?php
declare(strict_types=1);

function normalize(float &$value, float $min, float $max): void {
    $value = ($value - $min) / ($max - $min);
}

$voltage = 3.7;
normalize($voltage, 0.0, 5.0);
echo $voltage . "\n";   // 0.74 — original modified in place
```

Use references sparingly. They make code harder to reason about because callers can't tell at a glance that their variable will change. In most cases, returning a value is cleaner. References are appropriate for large data structures where copying is expensive, or when you need to modify multiple values (though returning an array is usually still cleaner).

### Variadic functions — variable argument count

```php
<?php
declare(strict_types=1);

function average(float ...$values): float {
    if (count($values) === 0) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

echo average(3.3, 3.7, 3.5, 3.6) . "\n";   // 3.525

// Splat operator — unpack an array into arguments
$readings = [12.1, 11.9, 12.3, 12.0];
echo average(...$readings) . "\n";           // 12.075
```

The `...` in a parameter list means "collect remaining arguments into an array." The same `...` at a call site means "unpack this array into individual arguments." You'll use the splat operator constantly when working with arrays and function calls.

## Return types — including void, nullable, union

```php
<?php
declare(strict_types=1);

// void — function produces side effects, returns nothing
function logEvent(string $event): void {
    echo "[" . date("H:i:s") . "] $event\n";
    // return; is implicit — returning a value here is a TypeError
}

// Nullable — can return the type OR null
function findDevice(string $id): ?string {
    $devices = ["vend-001" => "192.168.1.10", "vend-002" => "192.168.1.11"];
    return $devices[$id] ?? null;   // null if not found
}

$ip = findDevice("vend-001");
if ($ip !== null) {
    echo "Device IP: $ip\n";
}

// Union types — PHP 8, can return one of several types
function parseValue(string $input): int|float|bool {
    if ($input === "true")  return true;
    if ($input === "false") return false;
    if (str_contains($input, ".")) return (float)$input;
    return (int)$input;
}

var_dump(parseValue("3.14"));    // float(3.14)
var_dump(parseValue("42"));      // int(42)
var_dump(parseValue("true"));    // bool(true)
```

The nullable `?string` shorthand is equivalent to `string|null`. Prefer the `?Type` syntax for simple nullable returns — save union types for genuinely different return types.

## Scope — where variables live

PHP has strict scope rules that surprise people coming from JavaScript or Python:

```php
<?php
declare(strict_types=1);

$threshold = 80.0;   // global scope

function checkTemp(float $temp): string {
    // $threshold is NOT accessible here — PHP doesn't auto-import globals
    // echo $threshold;  // Undefined variable — this would be a notice/warning

    return $temp > 80.0 ? "over limit" : "normal";  // hardcoded — bad practice
}

// The RIGHT way — pass it as a parameter
function checkTempCorrect(float $temp, float $threshold): string {
    return $temp > $threshold ? "over limit" : "normal";
}

echo checkTempCorrect(87.5, $threshold) . "\n";
```

This is intentional design. Functions that don't depend on global state are easier to test and reuse. The `global` keyword exists to import globals into function scope, but treat it as a code smell — if you need it, your design probably needs a parameter instead.

## First-class functions — callables and closures

Functions in PHP are values. You can store them in variables, pass them to other functions, and return them from functions:

```php
<?php
declare(strict_types=1);

// Anonymous function (closure) stored in a variable
$double = function(float $x): float {
    return $x * 2.0;
};

echo $double(3.3) . "\n";   // 6.6

// Arrow function — PHP 7.4+, single expression, auto-captures outer scope
$factor = 1.8;
$scale  = fn(float $x): float => $x * $factor;   // $factor captured automatically

echo $scale(5.0) . "\n";   // 9.0

// Passing a function as an argument
function applyToAll(array $values, callable $fn): array {
    return array_map($fn, $values);
}

$voltages  = [3.3, 5.0, 12.0, 24.0];
$millivolts = applyToAll($voltages, fn(float $v): float => $v * 1000.0);

foreach ($millivolts as $mv) {
    echo $mv . " mV\n";
}
```

Arrow functions (`fn() =>`) automatically capture variables from the enclosing scope — you don't need `use`. Regular closures require explicit `use ($var)` to capture outer variables. Arrow functions are limited to a single expression; for multi-line logic, use a regular closure.

## The `use` keyword in closures

```php
<?php
declare(strict_types=1);

function makeThresholdChecker(float $limit): callable {
    // Regular closure captures $limit via use
    return function(float $value) use ($limit): bool {
        return $value > $limit;
    };
}

$isCritical = makeThresholdChecker(90.0);
$isWarning  = makeThresholdChecker(75.0);

$readings = [65.0, 78.0, 91.0, 83.0];

foreach ($readings as $temp) {
    $status = match(true) {
        $isCritical($temp) => "CRITICAL",
        $isWarning($temp)  => "WARNING",
        default            => "OK",
    };
    echo "$temp°C — $status\n";
}
```

`makeThresholdChecker` returns a closure configured with a specific limit — each call produces a different checker. This is a real pattern: factory functions that return specialized callables. You'll use this in event systems, middleware, and validation pipelines.

## Building your utility library

Put all reusable functions in a shared file. This is the manual version of what autoloading (Day 21) will do automatically:

```php
<?php
// utils.php
declare(strict_types=1);

function clamp(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function truncate(string $text, int $length = 100, string $suffix = '...'): string {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length - strlen($suffix)) . $suffix;
}

function bytesToHex(array $bytes): string {
    return implode(' ', array_map(fn(int $b): string => sprintf('0x%02X', $b), $bytes));
}
```

```php
<?php
// main.php
declare(strict_types=1);

require_once 'utils.php';

echo clamp(150.0, 0.0, 100.0) . "\n";          // 100
echo slugify("Vending Machine #3 (Floor 2)") . "\n";  // vending-machine-3-floor-2
echo truncate("A long device description...", 20) . "\n";
echo bytesToHex([0x01, 0xAB, 0xFF, 0x3C]) . "\n";     // 0x01 0xAB 0xFF 0x3C
```

`require_once` includes a file exactly once — if you include it again, PHP skips it. `require` (without `_once`) includes every time. `include` and `include_once` are softer versions that emit a warning instead of a fatal error if the file is missing. In practice: use `require_once` for dependencies, `require` for templates.

---

## Today's exercise

![[Pasted image 20260602224629.png]]
Part B is the most important one today. `makeThresholdChecker` returning a configured callable is a genuine design pattern — it's how you'd build a pluggable alert system in a real fleet management backend without hardcoding limits everywhere. Once you've written it, you'll recognize this shape constantly in professional PHP code.

Paste your code when done and we'll review before Day 5 — arrays, which is where PHP really starts to feel powerful.