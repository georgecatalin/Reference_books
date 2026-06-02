
#PHP 

# PHP environment + your first script

Let's build the right mental model first, then get your hands on code immediately.

## The one concept that explains everything

PHP is a server-side language. That means PHP code never runs in your browser — it runs on a machine (your computer today, a server later), produces output (usually HTML), and sends that output to the browser. The browser never sees your PHP source code.

```
Browser request → Web server → PHP executes → HTML response → Browser renders
```

That's the entire mental model. Everything else in PHP is just filling in the details of that pipeline.

## Step 1 — Install PHP

On Ubuntu (which you're running), this is a single command:

```bash
sudo apt update && sudo apt install php8.3-cli -y
```

Verify it worked:

```bash
php --version
```

You should see something like `PHP 8.3.x`. You now have everything you need for today — no Apache, no Nginx, no Docker. PHP has a built-in dev server we'll use in a moment.

## Step 2 — The two ways to run PHP

**From the command line (CLI mode)** — PHP reads a file and prints to the terminal. No browser involved.

```bash
php hello.php
```

**From the built-in web server** — PHP listens on a port, handles HTTP requests, and you visit it in a browser.

```bash
php -S localhost:8000
```

Today you'll use both. CLI first to understand the basics without any HTTP noise, then the web server to see real page output.

## Step 3 — Your first three scripts

Create a working directory:

```bash
mkdir ~/php-practice && cd ~/php-practice
```

**Script 1 — CLI hello world.** Create `hello.php`:

```php
<?php

$name = "George";
$language = "PHP";

echo "Hello, $name!\n";
echo "Welcome to $language.\n";
echo "Today is: " . date("Y-m-d") . "\n";
```

Run it: `php hello.php`

A few things to notice right away:

- Every PHP file starts with `<?php`. No closing tag needed if the file is pure PHP — leaving it off avoids a class of subtle bugs.
- `echo` outputs text. The `\n` is a newline (only visible in CLI — in HTML you'd use `<br>`).
- String interpolation: variables inside double-quoted strings expand automatically. `"Hello, $name"` outputs `Hello, George`.
- Concatenation uses `.` (dot), not `+`. This trips up everyone coming from other languages.

**Script 2 — Variables and types.** Create `types.php`:

```php
<?php

$integer   = 42;
$float     = 3.14;
$string    = "embedded systems";
$bool      = true;
$nothing   = null;

echo gettype($integer) . "\n";   // integer
echo gettype($float)   . "\n";   // double
echo gettype($string)  . "\n";   // string
echo gettype($bool)    . "\n";   // boolean
echo gettype($nothing) . "\n";   // NULL

// var_dump shows type AND value — use this constantly while learning
var_dump($integer);
var_dump($bool);
var_dump($nothing);
```

Run it: `php types.php`

`var_dump()` is your best debugging friend right now. Whenever you're unsure what a variable actually contains, `var_dump()` it.

**Script 3 — A dynamic HTML page.** Create `index.php`, then start the dev server:

```php
<?php
$title    = "My PHP Page";
$items    = ["Resistor", "Capacitor", "Microcontroller", "MOSFET"];
$now      = date("H:i:s");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1><?= $title ?></h1>
    <p>Server time: <?= $now ?></p>

    <h2>Components</h2>
    <ul>
        <?php foreach ($items as $item): ?>
            <li><?= $item ?></li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
```

```bash
php -S localhost:8000
```

Open `http://localhost:8000/index.php` in your browser. Hit refresh a few times — watch the time update. That's PHP executing fresh on every request.

Two things to note here: `<?= $variable ?>` is shorthand for `<?php echo $variable; ?>` — use it in HTML templates constantly. And `foreach ($items as $item)` iterates an array; we'll go deep on arrays on Day 5.

## Step 4 — Understand what just happened

Here's the request cycle you just experienced, made concrete:## Your Day 1 deliverable

Before moving to Day 2, build this from scratch without copy-pasting:

Create `profile.php` — a dynamic page that outputs:

- Your name and today's date in an `<h1>`
- A list of 4–5 things you know (languages, tools, protocols — anything)
- A "built at" timestamp showing the exact time the page was generated
- Serve it through `php -S localhost:8000` and verify it in your browser

![[Pasted image 20260602222645.png]]

That's it. No forms, no database, no framework. Just PHP generating HTML from variables.

When you've got it running, paste your `profile.php` code here and I'll review it and point out anything worth fixing before we move to Day 2 (variables, types, and the type-juggling traps that catch everyone).


**Teach me Day 1 : PHP environment + your first script. Start with the key concept, then give me a real hands-on exercise I can do right now.**

I already just taught you Day 1 in full above — scroll up and you'll find everything: the mental model, the install command, all three scripts, the request cycle diagram, and your deliverable exercise (`profile.php`).

Jump straight to the deliverable — create `profile.php`, serve it with `php -S localhost:8000`, and paste your code here when it's running. That's where the real learning happens.

[[Roadmap]]