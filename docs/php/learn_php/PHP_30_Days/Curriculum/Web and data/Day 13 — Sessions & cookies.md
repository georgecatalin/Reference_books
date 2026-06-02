

Every page you've built so far is stateless — each request knows nothing about the one before it. Sessions and cookies are how you give PHP memory across requests. Today you build that plumbing from scratch, which makes auth (Day 14) straightforward.

## The mental model — where state lives

```
Without sessions:                    With sessions:
─────────────────                    ──────────────
Request 1: who are you? → ?          Request 1: here's a cookie with your session id
Request 2: who are you? → ?          Request 2: I see your cookie → I know who you are
Request 3: who are you? → ?          Request 3: still you, session still valid
```

A session is a server-side key-value store. PHP creates a file on disk (by default in `/tmp`) keyed by a random session ID. The browser stores only that ID in a cookie. On every request PHP reads the cookie, finds the right file, and loads the data. You never store session data in the cookie itself — only the ID.

## Part 1 — Sessions

### Starting and using a session

```php
<?php
declare(strict_types=1);

// session_start() must be called before any output
// and before you read or write $_SESSION
session_start();

// Write to session
$_SESSION["user_id"]   = 42;
$_SESSION["username"]  = "george";
$_SESSION["last_seen"] = time();

// Read from session — always use ?? for safety
$username  = $_SESSION["username"]  ?? null;
$lastSeen  = $_SESSION["last_seen"] ?? null;

echo "Hello, $username\n";
echo "Last seen: " . ($lastSeen ? date("H:i:s", $lastSeen) : "never") . "\n";

// Check if a key exists
if (isset($_SESSION["user_id"])) {
    echo "Logged in as user #{$_SESSION['user_id']}\n";
}

// Remove one key
unset($_SESSION["last_seen"]);

// Destroy the entire session — logout
session_destroy();
```

`session_start()` at the top of every file that needs session access. No exceptions, no "only if needed" — just always call it. The overhead is minimal and conditional session starts are a source of bugs.

### Session configuration — the secure defaults

PHP's default session settings are not production-ready. Set these before `session_start()`:

```php
<?php
declare(strict_types=1);

// Set before session_start() — these configure the session cookie
ini_set("session.cookie_httponly", "1");   // JS cannot read the cookie
ini_set("session.cookie_secure", "1");     // HTTPS only (use 0 in local dev)
ini_set("session.cookie_samesite", "Lax"); // CSRF protection
ini_set("session.use_strict_mode", "1");   // reject unrecognized session IDs
ini_set("session.gc_maxlifetime", "3600"); // sessions expire after 1 hour

session_start();
```

These five settings matter:

- `httponly` — prevents JavaScript from reading the session cookie. Makes XSS attacks unable to steal the session ID.
- `secure` — sends the cookie only over HTTPS. Set to `0` during local development.
- `samesite=Lax` — browser only sends the cookie on same-site requests and top-level navigation. Blocks most CSRF attacks.
- `use_strict_mode` — rejects session IDs that PHP didn't create. Blocks session fixation attacks.
- `gc_maxlifetime` — how long before PHP's garbage collector removes old session files.

### Session fixation — and how to prevent it

Session fixation is an attack where an attacker sets a known session ID before the user logs in, then hijacks the session after login. The fix is always to regenerate the session ID on privilege change:

```php
<?php
declare(strict_types=1);

session_start();

function login(int $userId, string $username): void {
    // Regenerate session ID on login — invalidates any fixated session
    // true = delete the old session file
    session_regenerate_id(true);

    $_SESSION["user_id"]    = $userId;
    $_SESSION["username"]   = $username;
    $_SESSION["logged_in"]  = true;
    $_SESSION["login_time"] = time();
}

function logout(): void {
    // Clear session data
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Destroy server-side session data
    session_destroy();
}

function isLoggedIn(): bool {
    return isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}

function requireLogin(string $redirectTo = "/login.php"): void {
    if (!isLoggedIn()) {
        header("Location: $redirectTo");
        exit;
    }
}
```

`session_regenerate_id(true)` on login is not optional — it's the fix for session fixation. Call it every time a user's privilege level changes: login, role change, sudo-style elevation.

### Flash messages — the right implementation

Flash messages are session values that survive exactly one redirect:

```php
<?php
declare(strict_types=1);

session_start();

function flashSet(string $type, string $message): void {
    $_SESSION["_flash"][] = ["type" => $type, "message" => $message];
}

function flashGet(): array {
    $messages = $_SESSION["_flash"] ?? [];
    unset($_SESSION["_flash"]);   // consume — show once only
    return $messages;
}

function flashHas(): bool {
    return !empty($_SESSION["_flash"]);
}

// Usage in a handler
flashSet("success", "Machine vend-001 registered.");
flashSet("info",    "3 machines need restocking.");
header("Location: /machines");
exit;

// Usage in a template (after redirect)
foreach (flashGet() as $flash) {
    $type    = htmlspecialchars($flash["type"]);
    $message = htmlspecialchars($flash["message"]);
    echo "<div class=\"alert alert-$type\">$message</div>";
}
```

Storing multiple flash messages in an array (not a single value) means you can flash several messages in one request. The `[]` append syntax adds each one without overwriting.

### Session timeout — active enforcement

PHP's garbage collector eventually cleans up expired sessions, but it's probabilistic. Enforce timeout actively in your code:

```php
<?php
declare(strict_types=1);

session_start();

const SESSION_TIMEOUT = 1800;   // 30 minutes

function checkSessionTimeout(): void {
    if (!isset($_SESSION["last_activity"])) {
        $_SESSION["last_activity"] = time();
        return;
    }

    $idle = time() - $_SESSION["last_activity"];

    if ($idle > SESSION_TIMEOUT) {
        // Session expired — log out cleanly
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $_SESSION["_flash"][] = [
            "type"    => "info",
            "message" => "Your session expired. Please log in again.",
        ];
        header("Location: /login.php");
        exit;
    }

    // Refresh activity timestamp on every request
    $_SESSION["last_activity"] = time();
}

// Call this near the top of every protected page
checkSessionTimeout();
```

## Part 2 — Cookies

Sessions use a cookie under the hood, but sometimes you need your own cookies — for "remember me" functionality, user preferences, or tracking state that should survive browser restarts.

### Setting and reading cookies

```php
<?php
declare(strict_types=1);

// setcookie() must be called before any output — same rule as header()

// Basic cookie — expires when browser closes (session cookie)
setcookie("theme", "dark");

// Persistent cookie — expires in 30 days
setcookie(
    name:     "preferred_floor",
    value:    "2",
    expires:  time() + (30 * 24 * 60 * 60),   // 30 days from now
    path:     "/",
    domain:   "",
    secure:   false,   // true in production (HTTPS only)
    httponly: true
);

// Read a cookie — always use ?? default
$theme          = $_COOKIE["theme"]           ?? "light";
$preferredFloor = $_COOKIE["preferred_floor"] ?? null;

echo "Theme: $theme\n";
echo "Floor: " . ($preferredFloor ?? "all") . "\n";

// Delete a cookie — set expiry in the past
setcookie("preferred_floor", "", time() - 3600, "/");
```

Cookie values are strings. If you need to store structured data, JSON-encode it — but keep cookies small (4KB limit) and never store sensitive data in them. Cookies are visible to the user and can be modified.

### Named arguments for setcookie

PHP 8 supports named arguments, which makes `setcookie` readable:

```php
<?php
declare(strict_types=1);

// Without named args — easy to get the order wrong
setcookie("pref", "dark", time() + 86400, "/", "", false, true);

// With named args — self-documenting
setcookie(
    name:     "pref",
    value:    "dark",
    expires:  time() + 86400,
    path:     "/",
    secure:   false,
    httponly: true
);
```

### A "remember me" token — the right pattern

"Remember me" is a security-sensitive feature. The naive implementation (store user ID in a cookie) is easily forged. The correct implementation uses a cryptographically random token stored in the database:

```php
<?php
declare(strict_types=1);

// Store a remember-me token in the database
// Table: remember_tokens (user_id, token_hash, expires_at, created_at)

function createRememberToken(PDO $pdo, int $userId): string {
    // Generate a cryptographically random token
    $token     = bin2hex(random_bytes(32));   // 64-char hex string
    $tokenHash = hash("sha256", $token);      // store hash, not raw token

    $pdo->prepare("
        INSERT INTO remember_tokens (user_id, token_hash, expires_at)
        VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 DAY))
    ")->execute([
        ":user_id"    => $userId,
        ":token_hash" => $tokenHash,
    ]);

    return $token;   // return raw token — sent to browser, never stored in DB
}

function validateRememberToken(PDO $pdo, string $token): int|false {
    $tokenHash = hash("sha256", $token);

    $row = $pdo->prepare("
        SELECT user_id FROM remember_tokens
        WHERE token_hash = :hash AND expires_at > NOW()
    ");
    $row->execute([":hash" => $tokenHash]);
    $result = $row->fetch();

    return $result ? (int)$result["user_id"] : false;
}

function setRememberCookie(string $token): void {
    setcookie(
        name:     "remember_token",
        value:    $token,
        expires:  time() + (30 * 24 * 60 * 60),
        path:     "/",
        secure:   true,    // HTTPS only
        httponly: true
    );
}

function clearRememberCookie(PDO $pdo, string $token): void {
    // Delete from database
    $pdo->prepare("DELETE FROM remember_tokens WHERE token_hash = :hash")
        ->execute([":hash" => hash("sha256", $token)]);

    // Delete from browser
    setcookie("remember_token", "", time() - 3600, "/");
}
```

The pattern: raw token in the browser cookie, hashed token in the database. Even if the database is compromised, the attacker gets hashes — useless without the raw tokens. `random_bytes(32)` is cryptographically secure; never use `rand()` or `uniqid()` for security tokens.

## Part 3 — Putting it together

A bootstrap file that handles session setup and "remember me" auto-login:

```php
<?php
// bootstrap.php — included at the top of every page
declare(strict_types=1);

ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
ini_set("session.gc_maxlifetime", "3600");

session_start();

// Enforce active session timeout
if (isset($_SESSION["user_id"])) {
    $idle = time() - ($_SESSION["last_activity"] ?? time());
    if ($idle > 1800) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        header("Location: /login.php?reason=timeout");
        exit;
    }
    $_SESSION["last_activity"] = time();
}

// Auto-login via remember-me cookie
if (!isset($_SESSION["user_id"]) && isset($_COOKIE["remember_token"])) {
    require_once __DIR__ . "/db.php";
    $pdo    = Database::connection();
    $userId = validateRememberToken($pdo, $_COOKIE["remember_token"]);

    if ($userId !== false) {
        // Valid token — log the user in
        $user = Database::fetch(
            "SELECT id, username FROM users WHERE id = :id",
            [":id" => $userId]
        );

        if ($user !== false) {
            session_regenerate_id(true);
            $_SESSION["user_id"]       = $user["id"];
            $_SESSION["username"]      = $user["username"];
            $_SESSION["logged_in"]     = true;
            $_SESSION["last_activity"] = time();
        }
    } else {
        // Token expired or invalid — clear the cookie
        setcookie("remember_token", "", time() - 3600, "/");
    }
}
```

---

## Today's exercise

![[Pasted image 20260602232145.png]]
Part A gives you session mechanics in a context where the behavior is immediately visible and testable — add something, refresh, it's still there, clear it, it's gone. That immediacy is what makes sessions click. Part B reinforces that cookies and sessions solve different problems: sessions for server-side state that should be secure, cookies for client-side preferences that can survive browser restarts.

The stretch goal's 5-minute timeout is deliberately short so you can sit and watch it fire. Once you've seen it expire and redirect in real time the mechanism is permanently clear — and Day 14's full authentication system slots right on top of everything you've built here.

Paste your code when done and we'll move to Day 14 — authentication.