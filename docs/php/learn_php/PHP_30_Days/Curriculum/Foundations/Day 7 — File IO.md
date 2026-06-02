

Embedded developers think in terms of peripherals and buses. Think of PHP file I/O the same way: the filesystem is a peripheral, and PHP gives you two layers to talk to it — a high-level API for simple reads and writes, and a low-level stream API for when you need precise control. You'll use the high-level API 90% of the time.

## The mental model

Every file operation in PHP can fail. The file might not exist, the path might be wrong, permissions might deny access, the disk might be full. Unlike C where you check return values manually, PHP's file functions can throw exceptions or return `false` — and beginners ignore that `false` until it causes a bug that's hard to trace. From day one: wrap file operations in `try/catch`, or check return values explicitly.

## High-level API — the functions you'll use most

### Reading

```php
<?php
declare(strict_types=1);

// Read entire file into a string — simplest possible read
$content = file_get_contents("config.txt");

if ($content === false) {
    throw new RuntimeException("Could not read config.txt");
}

echo $content;

// Read a remote URL the same way (if allow_url_fopen is on)
$json = file_get_contents("https://api.example.com/status");

// Read file into an array of lines — each line is one element
$lines = file("data.csv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $i => $line) {
    echo "Line $i: $line\n";
}
```

`FILE_IGNORE_NEW_LINES` strips the `\n` from each line. `FILE_SKIP_EMPTY_LINES` skips blank lines. Use both unless you specifically need the raw newlines.

### Writing

```php
<?php
declare(strict_types=1);

$data = "broker=mqtt.factory.local\nport=1883\nkeepalive=60\n";

// Write (overwrites if exists, creates if not)
$bytes = file_put_contents("config.txt", $data);

if ($bytes === false) {
    throw new RuntimeException("Write failed");
}

echo "Wrote $bytes bytes\n";

// Append — FILE_APPEND flag adds to end instead of overwriting
$entry = date("Y-m-d H:i:s") . " — Machine vend-001 came online\n";
file_put_contents("events.log", $entry, FILE_APPEND);

// LOCK_EX — exclusive lock during write, prevents race conditions
file_put_contents("events.log", $entry, FILE_APPEND | LOCK_EX);
```

`LOCK_EX` is the flag you add in production when multiple processes might write the same file simultaneously. On a single-process dev server it doesn't matter — in a fleet management backend it does.

## Checking files before touching them

```php
<?php
declare(strict_types=1);

$path = "config.txt";

// Existence and type checks
var_dump(file_exists($path));    // true/false — file or directory
var_dump(is_file($path));        // true only for regular files
var_dump(is_dir($path));         // true only for directories
var_dump(is_readable($path));    // can we read it?
var_dump(is_writable($path));    // can we write it?

// Size and metadata
echo filesize($path) . " bytes\n";
echo date("Y-m-d H:i:s", filemtime($path)) . "\n";   // last modified time

// Path manipulation — use these instead of string hacks
echo basename("/var/log/app/events.log") . "\n";   // events.log
echo dirname("/var/log/app/events.log")  . "\n";   // /var/log/app
echo pathinfo("/var/log/app/events.log", PATHINFO_EXTENSION) . "\n";  // log
```

## Low-level stream API — fopen/fread/fwrite/fclose

Use this when you need to read large files line by line (without loading everything into memory), or when you need precise seek control:

```php
<?php
declare(strict_types=1);

// Open modes:
// "r"  — read only, pointer at start
// "w"  — write only, truncates file, creates if missing
// "a"  — append, pointer at end, creates if missing
// "r+" — read+write, pointer at start
// "x"  — write only, fails if file already exists (safe creation)

$handle = fopen("data.csv", "r");

if ($handle === false) {
    throw new RuntimeException("Cannot open data.csv");
}

try {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === "") continue;
        echo $line . "\n";
    }
} finally {
    fclose($handle);   // always close — even if something throws
}
```

The `try/finally` pattern guarantees `fclose` runs even if an exception is thrown mid-loop. This is the PHP equivalent of RAII — the resource is always released. Important habit.

## Parsing a CSV file

CSV is the most common flat-file data format in industrial and enterprise PHP work:

```php
<?php
declare(strict_types=1);

// Create a sample CSV first
file_put_contents("machines.csv",
    "id,location,status,fill_pct\n" .
    "vend-001,Floor 1,online,85\n" .
    "vend-002,Floor 2,fault,12\n" .
    "vend-003,Lobby,online,100\n" .
    "vend-004,Canteen,offline,44\n"
);

$handle = fopen("machines.csv", "r");
if ($handle === false) {
    throw new RuntimeException("Cannot open machines.csv");
}

$headers = [];
$machines = [];

try {
    $rowIndex = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if ($rowIndex === 0) {
            $headers = $row;   // first row is headers
            $rowIndex++;
            continue;
        }
        // Combine headers with values — produces associative row
        $machines[] = array_combine($headers, $row);
        $rowIndex++;
    }
} finally {
    fclose($handle);
}

// Now $machines is a proper array of associative rows
foreach ($machines as $m) {
    echo "{$m['id']} ({$m['location']}): {$m['status']} — {$m['fill_pct']}% full\n";
}
```

`fgetcsv` handles quoted fields, embedded commas, and escaped characters correctly — never split CSV lines with `explode(",", $line)`. It looks simpler but breaks on any real-world CSV that has quoted fields.

## Writing a key=value config reader

A pattern you'll use in early projects before you reach Composer and `.env` files:

```php
<?php
declare(strict_types=1);

function readConfig(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException("Config file not readable: $path");
    }

    $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];

    foreach ($lines as $line) {
        // Skip comment lines
        if (str_starts_with(trim($line), "#")) {
            continue;
        }

        // Split on first = only — values may contain =
        $parts = explode("=", $line, 2);

        if (count($parts) !== 2) {
            continue;   // malformed line — skip silently
        }

        [$key, $value] = $parts;
        $config[trim($key)] = trim($value);
    }

    return $config;
}

// Create a sample config file
file_put_contents("mqtt.conf",
    "# MQTT broker configuration\n" .
    "broker=mqtt.factory.local\n" .
    "port=1883\n" .
    "keepalive=60\n" .
    "client_id=fleet-manager\n" .
    "tls=false\n"
);

$config = readConfig("mqtt.conf");
var_dump($config);

// Safe access with defaults
$port      = (int)($config["port"] ?? 1883);
$keepalive = (int)($config["keepalive"] ?? 30);
$tls       = ($config["tls"] ?? "false") === "true";

echo "Connecting to {$config['broker']}:$port (TLS: " . ($tls ? "yes" : "no") . ")\n";
```

## Logging with timestamps

A log writer you'll actually use:

```php
<?php
declare(strict_types=1);

function logEvent(
    string $level,
    string $message,
    string $path = "app.log",
    array  $context = []
): void {
    $timestamp = date("Y-m-d H:i:s");
    $ctx       = empty($context) ? "" : " " . json_encode($context);
    $line      = "[$timestamp] [$level] $message$ctx\n";

    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

logEvent("INFO",  "Fleet manager started");
logEvent("WARN",  "Machine below threshold", "app.log", ["id" => "vend-002", "fill_pct" => 12]);
logEvent("ERROR", "Connection refused",      "app.log", ["broker" => "mqtt.factory.local", "port" => 1883]);

// Read back and display
echo file_get_contents("app.log");
```

## Working with directories

```php
<?php
declare(strict_types=1);

// Create a directory (recursive = true creates parent dirs too)
if (!is_dir("logs/machines")) {
    mkdir("logs/machines", 0755, true);
}

// List directory contents
$files = scandir("logs");
foreach ($files as $file) {
    if ($file === "." || $file === "..") continue;
    echo $file . "\n";
}

// glob — pattern matching, returns array of paths
$logFiles = glob("logs/*.log");
foreach ($logFiles as $logPath) {
    $size = filesize($logPath);
    echo basename($logPath) . " — $size bytes\n";
}

// Delete a file
unlink("old-data.tmp");

// Rename / move
rename("temp.log", "logs/archive.log");
```

---

## Today's exercise

![[Pasted image 20260602225129.png]]

The stretch goal is worth doing — log rotation is one of those "obvious in hindsight" production requirements that every real application needs and beginners never think about until a log file fills a disk. The logic maps directly to what `logrotate` does on Linux, just in PHP.

Paste your code when ready. Day 8 is error handling — the last piece of Phase 1 before we hit HTTP and databases.