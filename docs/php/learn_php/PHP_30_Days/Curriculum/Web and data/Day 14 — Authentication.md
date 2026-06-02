
Sessions gave you memory across requests. Today you use that to build a complete login system from scratch — registration, login, logout, and protected pages. No libraries, no magic. Understanding auth at this level means you'll never be confused by what Laravel's auth scaffolding is doing under the hood.

## The mental model — what auth actually is

```
Authentication = proving who you are      (login)
Authorization  = proving what you can do  (permissions — Day 20+)
```

Today is authentication only. You verify identity — is this person who they claim to be? Authorization (can this user delete machines? can they see the admin panel?) comes later when you have roles.

## The users table

```sql
CREATE TABLE users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)     NOT NULL UNIQUE,
    email         VARCHAR(150)    NOT NULL UNIQUE,
    password_hash VARCHAR(255)    NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`password_hash` is 255 characters — `password_hash()` currently produces 60-character bcrypt hashes but the column is future-proofed for longer algorithms. Never make it shorter.

## password_hash and password_verify

These two functions are the entire PHP password story. Everything else is plumbing around them:

```php
<?php
declare(strict_types=1);

// Hashing — done at registration
$rawPassword = "correcthorsebatterystaple";
$hash        = password_hash($rawPassword, PASSWORD_BCRYPT);

echo $hash . "\n";
// $2y$10$... — different every time even for the same password (salted)

var_dump(password_verify($rawPassword, $hash));         // true
var_dump(password_verify("wrongpassword", $hash));      // false

// PASSWORD_DEFAULT = bcrypt today, will change as PHP evolves
// Use PASSWORD_BCRYPT explicitly if you need stability
// Cost factor — default is 10, higher = slower = more secure
$hash = password_hash($rawPassword, PASSWORD_BCRYPT, ["cost" => 12]);

// Check if a hash needs rehashing (e.g. after upgrading cost factor)
if (password_needs_rehash($hash, PASSWORD_BCRYPT, ["cost" => 12])) {
    $hash = password_hash($rawPassword, PASSWORD_BCRYPT, ["cost" => 12]);
    // Save new hash to database
}
```

Three things to understand about bcrypt:

- It is intentionally slow. That's the security feature — brute-forcing millions of hashes takes years instead of seconds.
- It includes a random salt. Two identical passwords produce different hashes. Never add your own salt.
- `password_verify` is timing-safe. It takes the same time regardless of how many characters match, preventing timing attacks.

Never use `md5()`, `sha1()`, or any general-purpose hash function for passwords. They're fast — which is exactly wrong for passwords.

## Registration

```php
<?php
// handlers/auth/register.php
declare(strict_types=1);

$errors = [];
$values = ["username" => "", "email" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["username"] = trim($_POST["username"] ?? "");
    $values["email"]    = trim($_POST["email"]    ?? "");
    $password           = $_POST["password"]        ?? "";
    $passwordConfirm    = $_POST["password_confirm"] ?? "";

    // Validate username
    if ($values["username"] === "") {
        $errors["username"] = "Username is required.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $values["username"])) {
        $errors["username"] = "Username must be 3–30 chars: letters, numbers, underscores.";
    } else {
        $exists = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM users WHERE username = :u",
            [":u" => $values["username"]]
        );
        if ($exists > 0) {
            $errors["username"] = "That username is already taken.";
        }
    }

    // Validate email
    if ($values["email"] === "") {
        $errors["email"] = "Email is required.";
    } elseif (filter_var($values["email"], FILTER_VALIDATE_EMAIL) === false) {
        $errors["email"] = "Please enter a valid email address.";
    } else {
        $exists = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM users WHERE email = :e",
            [":e" => $values["email"]]
        );
        if ($exists > 0) {
            $errors["email"] = "An account with that email already exists.";
        }
    }

    // Validate password
    if (strlen($password) < 8) {
        $errors["password"] = "Password must be at least 8 characters.";
    } elseif ($password !== $passwordConfirm) {
        $errors["password_confirm"] = "Passwords do not match.";
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ["cost" => 12]);

        Database::execute("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :hash)
        ", [
            ":username" => $values["username"],
            ":email"    => $values["email"],
            ":hash"     => $hash,
        ]);

        $_SESSION["flash"] = [
            "type"    => "success",
            "message" => "Account created. Please log in.",
        ];
        header("Location: /login");
        exit;
    }
}

ob_start();
require __DIR__ . "/../../templates/auth/register.html.php";
$content = ob_get_clean();
$title   = "Register";
require __DIR__ . "/../../templates/layout.php";
```

## Login

```php
<?php
// handlers/auth/login.php
declare(strict_types=1);

// Already logged in — redirect away
if (isLoggedIn()) {
    header("Location: /machines");
    exit;
}

$errors = [];
$values = ["email" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["email"] = trim($_POST["email"]    ?? "");
    $password        =      $_POST["password"] ?? "";

    // Deliberately vague error — don't tell attacker which field was wrong
    $genericError = "Invalid email or password.";

    if ($values["email"] === "" || $password === "") {
        $errors["form"] = $genericError;
    } else {
        $user = Database::fetch(
            "SELECT * FROM users WHERE email = :email",
            [":email" => $values["email"]]
        );

        // Always call password_verify even when user not found
        // This prevents timing attacks that reveal whether an email exists
        $hash = $user["password_hash"] ?? '$2y$12$invalidhashpadding00000000000000000000000000000000000';

        if ($user === false || !password_verify($password, $hash)) {
            $errors["form"] = $genericError;
        } else {
            // Valid credentials — establish session
            session_regenerate_id(true);

            $_SESSION["user_id"]       = $user["id"];
            $_SESSION["username"]      = $user["username"];
            $_SESSION["email"]         = $user["email"];
            $_SESSION["logged_in"]     = true;
            $_SESSION["login_time"]    = time();
            $_SESSION["last_activity"] = time();

            // Update last_login_at
            Database::execute(
                "UPDATE users SET last_login_at = NOW() WHERE id = :id",
                [":id" => $user["id"]]
            );

            // Check for remember me
            if (!empty($_POST["remember_me"])) {
                $token = bin2hex(random_bytes(32));
                $hash  = hash("sha256", $token);

                Database::execute("
                    INSERT INTO remember_tokens (user_id, token_hash, expires_at)
                    VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL 30 DAY))
                ", [":uid" => $user["id"], ":hash" => $hash]);

                setcookie(
                    name:     "remember_token",
                    value:    $token,
                    expires:  time() + (30 * 24 * 60 * 60),
                    path:     "/",
                    httponly: true
                );
            }

            $redirect = $_SESSION["intended_url"] ?? "/machines";
            unset($_SESSION["intended_url"]);

            header("Location: $redirect");
            exit;
        }
    }
}

ob_start();
require __DIR__ . "/../../templates/auth/login.html.php";
$content = ob_get_clean();
$title   = "Login";
require __DIR__ . "/../../templates/layout.php";
```

The dummy hash on the "user not found" path is critical. Without it, an attacker can tell from response timing whether an email exists in your system — the `password_verify` call takes ~100ms when the user exists (bcrypt) and ~0ms when it doesn't (short circuit). Always call `password_verify` regardless.

The `intended_url` pattern stores where the user was trying to go before being redirected to login, then sends them there after successful auth. Users expect this behavior.

## Logout

```php
<?php
// handlers/auth/logout.php
declare(strict_types=1);

// Logout should be POST — a GET logout is vulnerable to CSRF logout attacks
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /machines");
    exit;
}

// Clear remember-me token from database and browser
if (isset($_COOKIE["remember_token"])) {
    $tokenHash = hash("sha256", $_COOKIE["remember_token"]);
    Database::execute(
        "DELETE FROM remember_tokens WHERE token_hash = :hash",
        [":hash" => $tokenHash]
    );
    setcookie("remember_token", "", time() - 3600, "/");
}

// Clear session data
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: /login");
exit;
```

## The guard function — protecting pages

```php
<?php
// auth.php — included by bootstrap.php

function isLoggedIn(): bool {
    return !empty($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true;
}

function currentUser(): array|null {
    if (!isLoggedIn()) return null;
    return [
        "id"       => $_SESSION["user_id"],
        "username" => $_SESSION["username"],
        "email"    => $_SESSION["email"],
    ];
}

function requireLogin(string $redirectTo = "/login"): void {
    if (!isLoggedIn()) {
        // Remember where they were trying to go
        $_SESSION["intended_url"] = $_SERVER["REQUEST_URI"];
        header("Location: $redirectTo");
        exit;
    }
}

function requireGuest(string $redirectTo = "/machines"): void {
    if (isLoggedIn()) {
        header("Location: $redirectTo");
        exit;
    }
}
```

Usage at the top of any protected handler:

```php
<?php
// handlers/machines/list.php
declare(strict_types=1);

requireLogin();   // redirects to /login if not logged in — one line

$machines = Database::fetchAll("SELECT * FROM machines ORDER BY id");
// ... rest of handler
```

## The login form template

```php
<?php
// templates/auth/login.html.php
declare(strict_types=1);
?>
<h1>Log in</h1>

<?php if (isset($errors["form"])): ?>
    <div class="flash-error"><?= e($errors["form"]) ?></div>
<?php endif; ?>

<form method="POST" action="/login">
    <label>Email
        <input type="email" name="email"
               value="<?= e($values["email"]) ?>"
               autocomplete="email" required>
    </label>

    <label>Password
        <input type="password" name="password"
               autocomplete="current-password" required>
    </label>

    <label style="display:flex; align-items:center; gap:.5rem; font-weight:normal; margin-top:.75rem;">
        <input type="checkbox" name="remember_me" style="width:auto;">
        Remember me for 30 days
    </label>

    <br>
    <button type="submit" class="btn">Log in</button>
    <a href="/register">Create account</a>
</form>
```

## The registration form template

```php
<?php
// templates/auth/register.html.php
declare(strict_types=1);
?>
<h1>Create account</h1>

<form method="POST" action="/register">
    <label>Username
        <input type="text" name="username"
               value="<?= e($values["username"]) ?>"
               autocomplete="username" required>
        <?php if (isset($errors["username"])): ?>
            <span class="error-msg"><?= e($errors["username"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Email
        <input type="email" name="email"
               value="<?= e($values["email"]) ?>"
               autocomplete="email" required>
        <?php if (isset($errors["email"])): ?>
            <span class="error-msg"><?= e($errors["email"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Password
        <input type="password" name="password"
               autocomplete="new-password" required>
        <?php if (isset($errors["password"])): ?>
            <span class="error-msg"><?= e($errors["password"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Confirm password
        <input type="password" name="password_confirm"
               autocomplete="new-password" required>
        <?php if (isset($errors["password_confirm"])): ?>
            <span class="error-msg"><?= e($errors["password_confirm"]) ?></span>
        <?php endif; ?>
    </label>

    <br>
    <button type="submit" class="btn">Create account</button>
    <a href="/login">Already have an account?</a>
</form>
```

## Updated bootstrap — the complete flow

```php
<?php
// bootstrap.php — required at top of index.php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
ini_set("session.gc_maxlifetime", "3600");

session_start();

// Active timeout check
if (isLoggedIn()) {
    $idle = time() - ($_SESSION["last_activity"] ?? time());
    if ($idle > 1800) {
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $_SESSION["flash"] = [
            "type"    => "info",
            "message" => "Your session expired. Please log in again.",
        ];
        header("Location: /login");
        exit;
    }
    $_SESSION["last_activity"] = time();
}

// Remember-me auto-login
if (!isLoggedIn() && isset($_COOKIE["remember_token"])) {
    $tokenHash = hash("sha256", $_COOKIE["remember_token"]);
    $row = Database::fetch("
        SELECT user_id FROM remember_tokens
        WHERE token_hash = :hash AND expires_at > NOW()
    ", [":hash" => $tokenHash]);

    if ($row !== false) {
        $user = Database::fetch(
            "SELECT id, username, email FROM users WHERE id = :id",
            [":id" => $row["user_id"]]
        );
        if ($user !== false) {
            session_regenerate_id(true);
            $_SESSION["user_id"]       = $user["id"];
            $_SESSION["username"]      = $user["username"];
            $_SESSION["email"]         = $user["email"];
            $_SESSION["logged_in"]     = true;
            $_SESSION["last_activity"] = time();
        }
    } else {
        setcookie("remember_token", "", time() - 3600, "/");
    }
}
```

## Updated index.php — auth routes added

```php
<?php
// index.php
declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
$uri    = rtrim(strtok($_SERVER["REQUEST_URI"], "?"), "/") ?: "/";

$routes = [
    ["GET",  "/",                 "handlers/home.php"],
    ["GET",  "/register",         "handlers/auth/register.php"],
    ["POST", "/register",         "handlers/auth/register.php"],
    ["GET",  "/login",            "handlers/auth/login.php"],
    ["POST", "/login",            "handlers/auth/login.php"],
    ["POST", "/logout",           "handlers/auth/logout.php"],
    ["GET",  "/machines",         "handlers/machines/list.php"],
    ["GET",  "/machines/new",     "handlers/machines/create.php"],
    ["POST", "/machines",         "handlers/machines/create.php"],
    ["GET",  "/machines/edit",    "handlers/machines/edit.php"],
    ["POST", "/machines/update",  "handlers/machines/edit.php"],
    ["POST", "/machines/delete",  "handlers/machines/delete.php"],
    ["GET",  "/machines/view",    "handlers/machines/view.php"],
];

$handler = null;
foreach ($routes as [$rm, $ru, $rh]) {
    if ($method === $rm && $uri === $ru) {
        $handler = $rh;
        break;
    }
}

if ($handler === null) {
    http_response_code(404);
    echo "<h1>404 — Not found</h1>";
    exit;
}

require $handler;
```

---

## Today's exercise

![[Pasted image 20260602232432.png]]
The stretch goal — login throttling — is the difference between a login form and a production login form. Without it, an attacker can try millions of passwords automatically. Five attempts per 15 minutes per IP is a reasonable threshold that stops automated attacks without inconveniencing real users. The probabilistic cleanup pattern is worth understanding deeply — PHP's own session garbage collector uses the exact same approach, and you'll see it again in queue workers and cache expiry systems.


With Day 14 done you've completed the core of Phase 2. You now have HTTP, forms, a database, sessions, and auth all working together as a coherent application. Phase 3 starts with OOP on Day 18 — if you want to fill in Days 15–17 (security, file uploads, mini blog project) before moving on, those are worth doing. The blog on Day 17 is where everything from Phase 2 combines into one complete deliverable.

Paste your code when ready.