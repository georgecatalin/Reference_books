

You've seen variables in action. Today you build a precise mental model of how PHP actually thinks about types — because PHP's loose typing is both its most convenient feature and its most common source of bugs.

## The core concept: PHP is dynamically AND loosely typed

Two separate ideas that get confused:

"Dynamically typed" means a variable has no declared type — it holds whatever you assign to it, and that can change. Most scripting languages work this way.

"Loosely typed" (also called "weakly typed") means PHP will silently convert between types when it needs to. This is unique to PHP's flavor and is the part that trips people up.

```php
<?php
$x = "5";   // string
$x = $x + 3; // PHP converts "5" to integer 5, then adds 3
var_dump($x); // int(8)  ← the string is gone, PHP decided for you
```

This convenience becomes a liability the moment you're not expecting it. The antidote is `===` (strict comparison) and type declarations — both covered today.

## The 8 types you need to know

```php
<?php

// Scalar types — single values
$age      = 32;           // int
$voltage  = 3.3;          // float
$label    = "CAN bus";    // string
$enabled  = true;         // bool

// Special
$nothing  = null;         // null — absence of value

// Compound types (preview — deep dive later)
$pins     = [3, 14, 15];                     // array
$obj      = new stdClass();                  // object

// Callable and resource exist too — you'll meet them naturally later
```

Run `var_dump()` on each one. Notice PHP prints the type name alongside the value — that's your ground truth while learning.

## String details that matter in practice

PHP has four string syntaxes. Two of them you'll use daily:

```php
<?php

$protocol = "MQTT";
$port     = 1883;

// Double quotes — variables expand inside
$msg1 = "Protocol: $protocol on port $port";
echo $msg1 . "\n";   // Protocol: MQTT on port 1883

// Single quotes — nothing expands, faster for static strings
$msg2 = 'Protocol: $protocol on port $port';
echo $msg2 . "\n";   // Protocol: $protocol on port $port  (literal)

// Curly braces to isolate a variable from surrounding text
$type = "tool";
echo "This is a {$type}box\n";   // toolbox  (without braces: $toolbox = undefined)

// Concatenation with dot operator
$full = "Port: " . $port . " (default)";
echo $full . "\n";
```

The rule of thumb: use single quotes for static strings (no variables), double quotes when you need interpolation. Never mix them randomly — be intentional.

## The type juggling traps — know these cold

These are the ones that cause real bugs in production:

```php
<?php

// Trap 1: loose comparison with ==
var_dump(0 == "foo");    // true  in PHP 7, false in PHP 8 — version-dependent!
var_dump(0 == "");       // true  in PHP 7, false in PHP 8
var_dump("1" == "01");   // true  — both cast to int 1
var_dump(100 == "1e2");  // true  — scientific notation cast to float

// Trap 2: empty string, "0", and null are all "falsy"
$values = ["", "0", 0, null, false, [], "false"];
foreach ($values as $v) {
    if (!$v) {
        echo var_export($v, true) . " is falsy\n";
    }
}
// "false" (the string) is TRUTHY — only the boolean false is falsy

// Trap 3: integer overflow silently becomes float
$big = PHP_INT_MAX;
var_dump($big);        // int(9223372036854775807)
var_dump($big + 1);    // float(9.2233720368548E+18)  ← no error, silent change

// Trap 4: string-to-number conversion is greedy but stops early
var_dump((int)"42abc");   // int(42)   — takes what it can
var_dump((int)"abc42");   // int(0)    — can't start, gives 0
```

## Strict comparison — the rule you follow from day one

```php
<?php

$val = "42";

// == checks value after type coercion (dangerous)
var_dump($val == 42);    // true  — "42" became 42

// === checks value AND type (safe)
var_dump($val === 42);   // false — string !== int
var_dump($val === "42"); // true  — same type, same value

// Same for !=  vs  !==
var_dump($val != 42);    // false (dangerous)
var_dump($val !== 42);   // true  (correct)
```

Commit to this rule now: always use `===` and `!==`. Use `==` only when you explicitly want type coercion and you can explain why.

## Type casting and type checking

```php
<?php

$input = "3.7";   // comes from a form, always a string

// Casting — explicit conversion
$int   = (int)$input;     // 3      (truncates, not rounds)
$float = (float)$input;   // 3.7
$bool  = (bool)$input;    // true   (non-empty string)
$str   = (string)3.14;    // "3.14"

// Type checking functions
var_dump(is_int(42));        // true
var_dump(is_string("hi"));   // true
var_dump(is_null(null));     // true
var_dump(is_numeric("3.7")); // true  — useful for form validation

// gettype() returns a string name
echo gettype(3.14) . "\n";   // double  (PHP calls floats "double" internally)
```

## Type declarations — the professional habit

From PHP 7+ you can enforce types on function parameters and return values. Do this in all non-trivial code:

```php
<?php

declare(strict_types=1);   // put this at the top of every file — enables strict mode

function celsiusToFahrenheit(float $celsius): float {
    return ($celsius * 9 / 5) + 32;
}

echo celsiusToFahrenheit(100.0) . "\n";  // 212
echo celsiusToFahrenheit(0.0)   . "\n";  // 32

// Without strict_types, PHP would silently cast "100" (string) to float
// With strict_types, passing a string throws a TypeError immediately
// You want the error — silent coercion hides bugs
```

`declare(strict_types=1)` must be the very first statement in a file (before any output, even whitespace). Make it a habit.

## Operators quick reference

```php
<?php

// Arithmetic
$a = 17;
$b = 5;
echo $a + $b  . "\n";   // 22
echo $a - $b  . "\n";   // 12
echo $a * $b  . "\n";   // 85
echo $a / $b  . "\n";   // 3.4   (always float if not evenly divisible)
echo $a % $b  . "\n";   // 2     (modulo — remainder)
echo $a ** $b . "\n";   // 1419857 (exponentiation)
echo intdiv($a, $b) . "\n"; // 3  (integer division)

// Assignment shorthands
$x = 10;
$x += 5;   // $x = 15
$x -= 3;   // $x = 12
$x *= 2;   // $x = 24
$x /= 4;   // $x = 6
$x **= 2;  // $x = 36
$x++;      // $x = 37  (post-increment)
++$x;      // $x = 38  (pre-increment — difference matters in expressions)

// Null coalescing — you'll use this constantly
$config = null;
$timeout = $config ?? 30;       // 30  (right side if left is null)
$timeout ??= 60;                // assigns 60 only if $timeout is null
echo $timeout . "\n";           // 30  (was already set)

// Spaceship operator — returns -1, 0, or 1 (useful for sorting)
echo (1 <=> 2) . "\n";   // -1
echo (2 <=> 2) . "\n";   // 0
echo (3 <=> 2) . "\n";   // 1
```

The `??` null coalescing operator is one of PHP's most practical features. You'll use it everywhere: reading array keys that might not exist, default config values, optional function parameters from request data.

---


![[Pasted image 20260602223244.png]]
The stretch goal is especially worth doing given your embedded background — the `strict_types` TypeError behaves exactly like a type mismatch caught at compile time in C. Silent coercion is the PHP equivalent of an implicit cast from `double` to `int` with no warning flag: technically valid, practically dangerous.

Paste your `sensor.php` when you're done and we'll review before moving to Day 3.

[[Day 1 - PHP environment + your first script]]