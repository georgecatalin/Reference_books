

This is the last day of Phase 1. You now have variables, types, control flow, functions, arrays, strings, dates, and file I/O. What you don't yet have is a disciplined way to handle things going wrong. That gap is what separates scripts that work on your machine from code that works reliably in production.

## The mental model — two separate systems

PHP has two parallel error systems that exist for historical reasons. Understanding both prevents confusion:

```
System 1: Errors     — old C-style, triggered by PHP internals and native functions
                        E_NOTICE, E_WARNING, E_FATAL, etc.
                        Handled with set_error_handler() or error reporting flags

System 2: Exceptions — OOP-style, thrown with throw, caught with try/catch
                        Throwable interface → Error | Exception hierarchies
                        What you use in your own code
```

Modern PHP (8.x) is pushing everything toward exceptions. Your job is to configure the error system properly, then work entirely in the exception system for your own code.

## Part 1 — Configuring the error system

```php
<?php
declare(strict_types=1);

// Development — show everything, immediately
error_reporting(E_ALL);
ini_set("display_errors", "1");    // print errors to screen

// Production — log everything, show nothing to users
error_reporting(E_ALL);
ini_set("display_errors", "0");    // never show errors to users
ini_set("log_errors", "1");
ini_set("error_log", "/var/log/php/app.log");
```

Two modes, never mixed. In development you want to see errors instantly. In production you want them logged silently — showing a raw PHP error to a user is both a bad UX and a security leak (stack traces expose file paths, class names, sometimes credentials).

The practical way to switch between modes:

```php
<?php
declare(strict_types=1);

// Read from environment — set APP_ENV=production on your server
$env = getenv("APP_ENV") ?: "development";

if ($env === "production") {
    error_reporting(E_ALL);
    ini_set("display_errors", "0");
    ini_set("log_errors", "1");
    ini_set("error_log", __DIR__ . "/logs/php.log");
} else {
    error_reporting(E_ALL);
    ini_set("display_errors", "1");
}
```

## Part 2 — The Exception hierarchy

```
Throwable (interface — everything catchable)
├── Error (PHP engine errors)
│   ├── TypeError        — wrong type passed to typed function
│   ├── ValueError       — right type, invalid value
│   ├── ArithmeticError
│   │   └── DivisionByZeroError
│   ├── ParseError       — syntax error in eval'd code
│   └── UnhandledMatchError — match with no matching arm
└── Exception (application errors)
    ├── RuntimeException
    │   ├── OutOfRangeException
    │   └── UnexpectedValueException
    ├── LogicException
    │   ├── InvalidArgumentException
    │   ├── OutOfBoundsException
    │   └── LengthException
    └── ... (your custom exceptions extend here)
```

The key distinction: `Error` subclasses are things PHP itself throws — you generally don't throw them yourself. `Exception` subclasses are for application-level failures — invalid input, missing files, failed connections. You throw these from your own code.

## Part 3 — try / catch / finally

```php
<?php
declare(strict_types=1);

function divide(float $a, float $b): float {
    if ($b === 0.0) {
        throw new DivisionByZeroError("Cannot divide $a by zero");
    }
    return $a / $b;
}

// Basic try/catch
try {
    echo divide(10.0, 2.0) . "\n";   // 5
    echo divide(10.0, 0.0) . "\n";   // throws
} catch (DivisionByZeroError $e) {
    echo "Math error: " . $e->getMessage() . "\n";
}

// Catching multiple exception types
try {
    $result = divide(10.0, 0.0);
} catch (DivisionByZeroError $e) {
    echo "Division error: " . $e->getMessage() . "\n";
} catch (TypeError $e) {
    echo "Type error: " . $e->getMessage() . "\n";
} catch (\Throwable $e) {
    // Catch-all — catches both Error and Exception
    echo "Unexpected: " . $e->getMessage() . "\n";
}

// finally — runs regardless of whether an exception was thrown
function readFile(string $path): string {
    $handle = fopen($path, "r");
    if ($handle === false) {
        throw new RuntimeException("Cannot open: $path");
    }
    try {
        return fread($handle, filesize($path));
    } finally {
        fclose($handle);   // always runs — even if fread throws
    }
}
```

`finally` is your resource cleanup guarantee. Whenever you acquire something — a file handle, a database connection, a lock — put the release in `finally`. It runs whether the `try` block succeeded, threw, or even returned early. This is the PHP equivalent of RAII or `defer` in Go.

## Part 4 — Custom Exception classes

Don't just throw generic `RuntimeException` everywhere. Custom exceptions let callers catch specific failure types and handle them differently:

```php
<?php
declare(strict_types=1);

// Base exception for your application domain
class AppException extends RuntimeException {}

// Specific exceptions for specific failure modes
class ConfigException extends AppException {}
class DeviceNotFoundException extends AppException {
    public function __construct(
        private readonly string $deviceId,
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: "Device not found: $deviceId",
            $code,
            $previous
        );
    }

    public function getDeviceId(): string {
        return $this->deviceId;
    }
}

class ConnectionException extends AppException {
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $message = "",
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: "Connection failed: $host:$port",
            0,
            $previous
        );
    }
}

// Usage
function findDevice(string $id): array {
    $devices = [
        "vend-001" => ["id" => "vend-001", "status" => "online"],
        "vend-002" => ["id" => "vend-002", "status" => "fault"],
    ];

    if (!array_key_exists($id, $devices)) {
        throw new DeviceNotFoundException($id);
    }

    return $devices[$id];
}

// Caller can handle specific failures differently
try {
    $device = findDevice("vend-999");
} catch (DeviceNotFoundException $e) {
    echo "Device not found: " . $e->getDeviceId() . "\n";
    // maybe redirect to a 404 page
} catch (AppException $e) {
    echo "Application error: " . $e->getMessage() . "\n";
    // generic app error handling
} catch (\Throwable $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n";
    // last resort
}
```

The rule: create a custom exception class any time a caller might need to handle that specific failure differently from other failures. If every failure is handled the same way, a generic exception is fine.

## Part 5 — Exception chaining

When you catch an exception and throw a different one, always pass the original as the `$previous` argument. This preserves the full chain of what went wrong:

```php
<?php
declare(strict_types=1);

class ConfigException extends \RuntimeException {}

function loadConfig(string $path): array {
    try {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("File read failed: $path");
        }
        $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return $config;
    } catch (\JsonException $e) {
        // Wrap the low-level JSON error in a domain-level ConfigException
        // $e is passed as $previous — the full chain is preserved
        throw new ConfigException("Invalid JSON in config: $path", 0, $e);
    } catch (\RuntimeException $e) {
        throw new ConfigException("Could not load config: $path", 0, $e);
    }
}

try {
    $config = loadConfig("bad.json");
} catch (ConfigException $e) {
    echo $e->getMessage() . "\n";

    // Walk the chain to find the root cause
    $prev = $e->getPrevious();
    while ($prev !== null) {
        echo "  Caused by: " . $prev->getMessage() . "\n";
        $prev = $prev->getPrevious();
    }
}
```

Exception chaining is how you translate low-level errors ("JSON parse error at line 3") into domain errors ("config file is corrupt") without losing diagnostic information. Always chain — never swallow the original.

## Part 6 — A global error boundary

Every real application needs a top-level handler for exceptions that slip through. This runs before PHP outputs an error page:

```php
<?php
declare(strict_types=1);

// bootstrap.php — included at the top of every entry point

function handleUncaughtException(\Throwable $e): void {
    $timestamp = date("Y-m-d H:i:s");
    $message   = sprintf(
        "[%s] UNCAUGHT %s: %s in %s:%d\nStack trace:\n%s\n",
        $timestamp,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    // Always log
    error_log($message);

    // In development: show it
    // In production: show a generic message
    $env = getenv("APP_ENV") ?: "development";
    if ($env === "production") {
        http_response_code(500);
        echo "An internal error occurred. Please try again later.";
    } else {
        echo "<pre>$message</pre>";
    }

    exit(1);
}

set_exception_handler("handleUncaughtException");

// Also convert PHP errors into exceptions — so you only deal with one system
set_error_handler(function(
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    // Only convert errors that match the current error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
```

`set_error_handler` with `ErrorException` is the bridge between PHP's two error systems. Once this is in place, a `file_get_contents` on a missing file generates an `ErrorException` you can catch — not a PHP warning that silently continues execution.

## Part 7 — JSON and its exceptions

JSON is the data format for APIs and config files. PHP 8 made it exception-friendly:

```php
<?php
declare(strict_types=1);

// Decoding — JSON_THROW_ON_ERROR makes it throw on bad input
$raw = '{"id": "vend-001", "status": "online"}';

try {
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    echo $data["id"] . "\n";   // vend-001
} catch (\JsonException $e) {
    throw new \RuntimeException("Invalid JSON payload: " . $e->getMessage(), 0, $e);
}

// Encoding — can also throw
try {
    $payload = [
        "id"     => "vend-001",
        "temp"   => 87.5,
        "online" => true,
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    echo $json . "\n";
} catch (\JsonException $e) {
    echo "Encoding failed: " . $e->getMessage() . "\n";
}

// Without JSON_THROW_ON_ERROR, json_decode returns null on failure
// and you have to check json_last_error() — avoid this pattern
```

Always use `JSON_THROW_ON_ERROR`. The old pattern of checking `json_last_error()` after every call is error-prone and verbose.

## Putting it all together — a robust file processor

```php
<?php
declare(strict_types=1);

class FileProcessingException extends \RuntimeException {}
class MalformedDataException extends \RuntimeException {}

function processDeviceLog(string $path): array {
    if (!file_exists($path)) {
        throw new FileProcessingException("Log file not found: $path");
    }

    if (!is_readable($path)) {
        throw new FileProcessingException("Log file not readable: $path");
    }

    $handle = fopen($path, "r");
    if ($handle === false) {
        throw new FileProcessingException("Could not open: $path");
    }

    $events = [];

    try {
        $lineNum = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNum++;
            $line = trim($line);

            if ($line === "" || str_starts_with($line, "#")) {
                continue;
            }

            try {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // Bad line — log and skip, don't abort the whole file
                error_log("Skipping malformed line $lineNum in $path: " . $e->getMessage());
                continue;
            }

            if (!isset($event["id"], $event["timestamp"], $event["type"])) {
                error_log("Skipping incomplete event at line $lineNum");
                continue;
            }

            $events[] = $event;
        }
    } finally {
        fclose($handle);   // always runs
    }

    if (empty($events)) {
        throw new MalformedDataException("No valid events found in: $path");
    }

    return $events;
}

// Create a test log file
file_put_contents("device.log",
    "# Device event log\n" .
    json_encode(["id" => "vend-001", "timestamp" => "2026-06-02T14:00:00Z", "type" => "online"]) . "\n" .
    "this is not json\n" .
    json_encode(["id" => "vend-001", "timestamp" => "2026-06-02T14:05:00Z", "type" => "alert"]) . "\n" .
    json_encode(["incomplete" => true]) . "\n"   // missing required fields
);

try {
    $events = processDeviceLog("device.log");
    echo "Processed " . count($events) . " events\n";
    foreach ($events as $e) {
        echo "  [{$e['timestamp']}] {$e['id']}: {$e['type']}\n";
    }
} catch (FileProcessingException $e) {
    echo "File error: " . $e->getMessage() . "\n";
} catch (MalformedDataException $e) {
    echo "Data error: " . $e->getMessage() . "\n";
}
```

Notice the deliberate decision at the bad JSON line: log and skip, don't abort. That's a real design choice — in a log processor you usually want to extract as much valid data as possible rather than failing the whole file on one bad line. Document that decision with a comment when you make it.

---

## Today's exercise

![[Pasted image 20260602225706.png]]
The stretch goal is worth doing before Phase 2. Once `set_error_handler` + `ErrorException` is in place you work in one unified system — every failure, whether from PHP internals or your own code, is a `Throwable` you can catch. That simplification pays dividends every day in Phase 2 when you're dealing with database connections, HTTP requests, and file uploads that can all fail in different ways.


**Phase 1 complete after this.** When you're done with Day 8 let me know and we'll move straight into Phase 2 — Day 9 is the HTTP request lifecycle, where all this foundation finally connects to a browser.