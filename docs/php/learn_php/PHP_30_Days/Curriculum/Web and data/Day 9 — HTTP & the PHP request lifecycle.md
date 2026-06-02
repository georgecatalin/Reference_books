
## The mental model — one request, one execution

Every time a browser makes a request to your PHP server, PHP starts fresh. No persistent memory between requests, no shared state, no background thread sitting idle. The entire script runs top to bottom, produces output, and dies. The next request starts a completely clean process.

```
Browser                    PHP Server
  │                            │
  │── GET /machines.php ───────▶│  PHP starts, runs machines.php top to bottom
  │                            │  opens files, builds HTML, closes files
  │◀── 200 OK + HTML ──────────│  PHP exits — everything is gone
  │                            │
  │── GET /machines.php ───────▶│  PHP starts again, completely fresh
  │◀── 200 OK + HTML ──────────│  PHP exits again
```

This is fundamentally different from a Qt application or an embedded firmware loop that runs continuously. Each HTTP request is an independent, short-lived process. State that needs to survive between requests must be stored externally — in a file, a database, or a session (which is backed by a file or database anyway).

## The superglobals — where request data lives

PHP makes request data available through special global arrays called superglobals. They're always available, in every scope, without needing `global` or being passed as parameters:

```php
<?php
declare(strict_types=1);

// $_GET    — URL query string parameters
// $_POST   — HTTP POST body (form submissions)
// $_SERVER — server and request metadata
// $_COOKIE — browser cookies
// $_FILES  — uploaded files
// $_SESSION — session data (after session_start())
// $_ENV    — environment variables
// $_REQUEST — merged GET + POST + COOKIE (avoid this one — too ambiguous)
```

## $_SERVER — the request metadata

```php
<?php
declare(strict_types=1);

// Run this and visit it in your browser — inspect every value
var_dump($_SERVER);

// The ones you'll use constantly:
$method      = $_SERVER["REQUEST_METHOD"];    // "GET", "POST", "PUT", "DELETE"
$uri         = $_SERVER["REQUEST_URI"];       // "/machines.php?floor=2"
$queryString = $_SERVER["QUERY_STRING"];      // "floor=2"
$host        = $_SERVER["HTTP_HOST"];         // "localhost:8000"
$userAgent   = $_SERVER["HTTP_USER_AGENT"];   // browser identifier string
$remoteIp    = $_SERVER["REMOTE_ADDR"];       // client IP address
$scriptPath  = $_SERVER["SCRIPT_FILENAME"];   // absolute path to current file
$https       = $_SERVER["HTTPS"] ?? "";       // "on" if HTTPS, empty if not
$contentType = $_SERVER["CONTENT_TYPE"] ?? ""; // "application/json" for API calls

// Is this an AJAX/fetch request?
$isXhr = ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest";

// Build the full current URL
$protocol = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
$fullUrl  = $protocol . "://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
echo $fullUrl . "\n";
```

## $_GET — URL query parameters

```php
<?php
declare(strict_types=1);

// URL: /machines.php?floor=2&status=online&limit=10

// Always use ?? to provide a default — key may not exist
$floor  = $_GET["floor"]  ?? null;
$status = $_GET["status"] ?? "all";
$limit  = (int)($_GET["limit"] ?? 20);

// Validate before using — $_GET values are always strings
if ($floor !== null && !ctype_digit($floor)) {
    // floor was supplied but isn't a number — bad request
    http_response_code(400);
    echo "Invalid floor parameter";
    exit;
}

$floor = $floor !== null ? (int)$floor : null;

echo "Floor: " . ($floor ?? "all") . "\n";
echo "Status: $status\n";
echo "Limit: $limit\n";

// Building URLs with query strings
$params = http_build_query([
    "floor"  => 2,
    "status" => "online",
    "page"   => 1,
]);
echo "/machines.php?$params\n";
// /machines.php?floor=2&status=online&page=1
```

`http_build_query` handles URL encoding automatically — don't build query strings by hand with string concatenation.

## $_POST — form submission data

```php
<?php
declare(strict_types=1);

// Only populated on POST requests
// Values are always strings (or arrays for multi-select/checkboxes)

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Safe read with defaults
    $name  = $_POST["name"]  ?? "";
    $email = $_POST["email"] ?? "";
    $port  = $_POST["port"]  ?? "";

    // Checkbox — only present in $_POST if checked
    $tls = isset($_POST["tls"]);   // true if checked, false if not

    // Multi-select / checkbox group — arrives as array
    $floors = $_POST["floors"] ?? [];   // e.g. ["1", "2", "3"]

    // Never use $_POST values directly in output or queries
    // Always validate and sanitize first
    echo "Name: " . htmlspecialchars($name) . "\n";
}
```

## Sending responses — headers, status codes, redirects

```php
<?php
declare(strict_types=1);

// HTTP status codes — use them correctly
http_response_code(200);   // OK (default)
http_response_code(201);   // Created
http_response_code(400);   // Bad Request — client sent invalid data
http_response_code(401);   // Unauthorized — not logged in
http_response_code(403);   // Forbidden — logged in but not allowed
http_response_code(404);   // Not Found
http_response_code(422);   // Unprocessable Entity — validation failed
http_response_code(500);   // Internal Server Error

// Setting headers — must be called before ANY output (including spaces)
header("Content-Type: application/json");
header("Content-Type: text/html; charset=UTF-8");
header("Cache-Control: no-cache, no-store, must-revalidate");

// Redirect — always exit immediately after
header("Location: /machines.php");
exit;

// Redirect with status code (default is 302 Found)
header("Location: /login.php", true, 302);
exit;

// Permanent redirect
header("Location: /new-url.php", true, 301);
exit;
```

Headers must be sent before any output — including whitespace, BOM characters, or HTML. A common mistake is having a space or newline before `<?php` in a file that's included. PHP will emit "headers already sent" warnings. The fix: never output anything before headers, and never have whitespace before `<?php`.

## GET vs POST — when to use each

```
GET                              POST
────────────────────────────────────────────────────────
Fetching data                    Changing data
URL is bookmarkable              URL is not bookmarkable
Parameters visible in URL        Parameters in request body
Safe to repeat (refresh = OK)    Not safe to repeat (resubmit dialog)
Cached by browser                Not cached
Max ~2000 chars of data          No practical size limit
Search, filtering, pagination    Forms, login, file upload, API writes
```

The rule: GET for reads, POST for writes. A search form uses GET so results are shareable via URL. A login form uses POST so credentials don't appear in browser history or server logs.

## Reading the request body directly

For JSON APIs, the body isn't in `$_POST` — it comes in raw:

```php
<?php
declare(strict_types=1);

// API endpoint expecting: POST /api/machines with JSON body
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);   // Method Not Allowed
    header("Allow: POST");
    exit;
}

$contentType = $_SERVER["CONTENT_TYPE"] ?? "";
if (!str_contains($contentType, "application/json")) {
    http_response_code(415);   // Unsupported Media Type
    echo json_encode(["error" => "Expected application/json"]);
    exit;
}

// Read raw POST body
$body = file_get_contents("php://input");

try {
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON: " . $e->getMessage()]);
    exit;
}

// Now $data is an associative array from the JSON body
$machineId = $data["id"]       ?? null;
$location  = $data["location"] ?? null;

// Validate, process, respond
http_response_code(201);
header("Content-Type: application/json");
echo json_encode(["status" => "created", "id" => $machineId]);
```

`php://input` is a read-once stream — read it once and store the result. It's empty on subsequent reads. This is the raw body — the same content `$_POST` would normally parse, but in API contexts you parse it yourself.

## A complete request router

Before frameworks, you route requests manually. Understanding this makes frameworks transparent:

```php
<?php
declare(strict_types=1);

// index.php — a minimal front controller
// All requests go through here (configure with php -S localhost:8000 index.php)

$method = $_SERVER["REQUEST_METHOD"];
$uri    = strtok($_SERVER["REQUEST_URI"], "?");   // strip query string

// Simple route table
$routes = [
    "GET"  => [
        "/"            => "handlers/home.php",
        "/machines"    => "handlers/machines_list.php",
        "/machines/new"=> "handlers/machines_form.php",
    ],
    "POST" => [
        "/machines"    => "handlers/machines_create.php",
    ],
];

$handler = $routes[$method][$uri] ?? null;

if ($handler === null) {
    http_response_code(404);
    echo "404 — Page not found";
    exit;
}

if (!file_exists($handler)) {
    http_response_code(500);
    echo "500 — Handler not found: $handler";
    exit;
}

require $handler;
```

This is a front controller — one entry point that dispatches to handlers. Every PHP framework (Laravel, Symfony, Slim) is a sophisticated version of exactly this pattern.

## Reading response headers and inspecting requests

While developing, use your browser's developer tools (Network tab) constantly. Here's how to inspect from PHP's side:

```php
<?php
declare(strict_types=1);

// Print everything PHP knows about the current request
function debugRequest(): void {
    echo "Method:  " . $_SERVER["REQUEST_METHOD"] . "\n";
    echo "URI:     " . $_SERVER["REQUEST_URI"] . "\n";
    echo "Host:    " . ($_SERVER["HTTP_HOST"] ?? "—") . "\n";
    echo "Type:    " . ($_SERVER["CONTENT_TYPE"] ?? "—") . "\n";
    echo "\n";

    if (!empty($_GET)) {
        echo "GET params:\n";
        foreach ($_GET as $k => $v) {
            echo "  $k = " . (is_array($v) ? json_encode($v) : $v) . "\n";
        }
    }

    if (!empty($_POST)) {
        echo "POST params:\n";
        foreach ($_POST as $k => $v) {
            echo "  $k = " . (is_array($v) ? json_encode($v) : $v) . "\n";
        }
    }

    $body = file_get_contents("php://input");
    if ($body !== "") {
        echo "Raw body: $body\n";
    }
}

debugRequest();
```

---

## Today's exercise

![[Pasted image 20260602230353.png]]
The stretch goal here connects directly to your MQTT architecture — the `POST /api/machines` endpoint is exactly what a broker-side webhook or a fleet management dashboard would call to register a new device. Test it with `curl` as suggested; getting comfortable with curl for API testing is a skill you'll use every day in Phase 3.

With Day 9 done you have the full Phase 2 foundation: HTTP → forms → database (Day 11) → sessions (Day 13) → auth (Day 14). Paste your code for review or call the next day when you're ready.