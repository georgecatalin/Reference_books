

Day 11 gave you the database layer. Today you wire it to HTTP — a complete web interface for creating, reading, updating, and deleting machines. This is the core of almost every business web application ever built.

## The structure

```
index.php          — front controller (routes requests)
db.php             — Database class + connection
handlers/
  machines/
    list.php       — GET  /machines
    create.php     — GET  /machines/new  +  POST /machines
    edit.php       — GET  /machines/{id}/edit  +  POST /machines/{id}
    delete.php     — POST /machines/{id}/delete
templates/
  layout.php       — shared HTML wrapper
  machines/
    list.html.php  — table of machines
    form.html.php  — shared create/edit form
```

Keeping handlers and templates separate is the beginning of MVC thinking. Handlers contain logic. Templates contain HTML. They never mix — no database calls inside templates, no raw HTML inside handlers.

## The front controller

```php
<?php
// index.php
declare(strict_types=1);

session_start();

require_once __DIR__ . "/db.php";

$method = $_SERVER["REQUEST_METHOD"];
$uri    = strtok($_SERVER["REQUEST_URI"], "?");

// Normalise trailing slash
$uri = rtrim($uri, "/") ?: "/";

// Route matching — ordered most-specific first
$routes = [
    ["GET",  "/machines/new",          "handlers/machines/create.php"],
    ["POST", "/machines",              "handlers/machines/create.php"],
    ["GET",  "/machines",              "handlers/machines/list.php"],
    ["POST", "/machines/delete",       "handlers/machines/delete.php"],
    ["POST", "/machines/update",       "handlers/machines/edit.php"],
    ["GET",  "/machines/edit",         "handlers/machines/edit.php"],
    ["GET",  "/",                      "handlers/home.php"],
];

$handler = null;
foreach ($routes as [$routeMethod, $routeUri, $routeHandler]) {
    if ($method === $routeMethod && $uri === $routeUri) {
        $handler = $routeHandler;
        break;
    }
}

if ($handler === null) {
    http_response_code(404);
    echo "<h1>404 — Page not found</h1>";
    exit;
}

if (!file_exists($handler)) {
    http_response_code(500);
    echo "<h1>500 — Handler missing: $handler</h1>";
    exit;
}

require $handler;
```

## The database layer

```php
<?php
// db.php
declare(strict_types=1);

class Database {
    private static ?PDO $pdo = null;

    public static function connection(): PDO {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO(
                    "mysql:host=127.0.0.1;dbname=fleet_db;charset=utf8mb4",
                    "fleet_user",
                    "strongpassword",
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                throw new RuntimeException("DB connection failed", 0, $e);
            }
        }
        return self::$pdo;
    }

    public static function fetchAll(string $sql, array $p = []): array {
        $s = self::connection()->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public static function fetch(string $sql, array $p = []): array|false {
        $s = self::connection()->prepare($sql);
        $s->execute($p);
        return $s->fetch();
    }

    public static function fetchColumn(string $sql, array $p = []): mixed {
        $s = self::connection()->prepare($sql);
        $s->execute($p);
        return $s->fetchColumn();
    }

    public static function execute(string $sql, array $p = []): int {
        $s = self::connection()->prepare($sql);
        $s->execute($p);
        return $s->rowCount();
    }
}
```

## The shared layout template

```php
<?php
// templates/layout.php
// Called by templates — expects $title and $content variables
declare(strict_types=1);

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? "Fleet Manager") ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px;
               margin: 0 auto; padding: 2rem; }
        nav a { margin-right: 1rem; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { padding: .5rem .75rem; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
        .flash-success { background: #d4edda; padding: .75rem; margin: 1rem 0;
                         border-radius: 4px; color: #155724; }
        .flash-error   { background: #f8d7da; padding: .75rem; margin: 1rem 0;
                         border-radius: 4px; color: #721c24; }
        .error-msg { color: #c00; font-size: .875rem; margin-top: .25rem; }
        .btn { padding: .4rem .8rem; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; border: none;
                      border-radius: 4px; }
        label { display: block; margin-top: .75rem; font-weight: 500; }
        input, select { width: 100%; padding: .4rem; margin-top: .25rem;
                        border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    </style>
</head>
<body>
<nav>
    <a href="/">Home</a>
    <a href="/machines">Machines</a>
    <a href="/machines/new">Register Machine</a>
</nav>
<hr>

<?php
// Flash messages — stored in session, shown once
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
if ($flash): ?>
    <div class="flash-<?= e($flash["type"]) ?>"><?= e($flash["message"]) ?></div>
<?php endif; ?>

<?= $content ?>
</body>
</html>
```

## READ — list all machines

```php
<?php
// handlers/machines/list.php
declare(strict_types=1);

$machines = Database::fetchAll("
    SELECT
        m.id,
        m.location,
        m.status,
        m.broker_ip,
        m.port,
        m.slots,
        COALESCE(SUM(i.quantity), 0) AS total_items,
        m.created_at
    FROM machines m
    LEFT JOIN inventory i ON i.machine_id = m.id
    GROUP BY m.id
    ORDER BY m.id
");

// Render into a variable, then wrap in layout
ob_start();
require __DIR__ . "/../../templates/machines/list.html.php";
$content = ob_get_clean();

$title = "Machines — Fleet Manager";
require __DIR__ . "/../../templates/layout.php";
```

```php
<?php
// templates/machines/list.html.php
declare(strict_types=1);
?>
<h1>Registered Machines</h1>
<p><a href="/machines/new">+ Register new machine</a></p>

<?php if (empty($machines)): ?>
    <p>No machines registered yet.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Location</th>
            <th>Status</th>
            <th>Broker</th>
            <th>Slots</th>
            <th>Items</th>
            <th>Registered</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($machines as $m): ?>
        <tr>
            <td><?= e($m["id"]) ?></td>
            <td><?= e($m["location"]) ?></td>
            <td><?= e($m["status"]) ?></td>
            <td><?= e($m["broker_ip"]) ?>:<?= e((string)$m["port"]) ?></td>
            <td><?= e((string)$m["slots"]) ?></td>
            <td><?= e((string)$m["total_items"]) ?></td>
            <td><?= e($m["created_at"]) ?></td>
            <td>
                <a href="/machines/edit?id=<?= e($m["id"]) ?>">Edit</a>
                &nbsp;
                <form method="POST" action="/machines/delete" style="display:inline"
                      onsubmit="return confirm('Delete <?= e($m["id"]) ?>?')">
                    <input type="hidden" name="id" value="<?= e($m["id"]) ?>">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
```

The `ob_start()` / `ob_get_clean()` pattern captures template output into a string, which is then injected into the layout as `$content`. This is the simplest possible template composition — no template engine needed.

## CREATE — the form and handler

```php
<?php
// handlers/machines/create.php
declare(strict_types=1);

$errors = [];
$values = [
    "id"        => "",
    "location"  => "",
    "broker_ip" => "",
    "port"      => "1883",
    "slots"     => "20",
    "status"    => "offline",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Read
    foreach (array_keys($values) as $field) {
        $values[$field] = trim($_POST[$field] ?? "");
    }

    // Validate
    if ($values["id"] === "") {
        $errors["id"] = "Machine ID is required.";
    } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,18}[a-z0-9]$/', $values["id"])) {
        $errors["id"] = "ID must be 3–20 chars: lowercase letters, numbers, hyphens.";
    } else {
        // Check uniqueness
        $exists = Database::fetchColumn(
            "SELECT COUNT(*) FROM machines WHERE id = :id",
            [":id" => $values["id"]]
        );
        if ((int)$exists > 0) {
            $errors["id"] = "Machine ID '{$values['id']}' is already registered.";
        }
    }

    if ($values["location"] === "") {
        $errors["location"] = "Location is required.";
    } elseif (strlen($values["location"]) > 100) {
        $errors["location"] = "Location must be 100 characters or fewer.";
    }

    if (filter_var($values["broker_ip"], FILTER_VALIDATE_IP) === false) {
        $errors["broker_ip"] = "Must be a valid IP address.";
    }

    $port = filter_var($values["port"], FILTER_VALIDATE_INT,
                       ["options" => ["min_range" => 1, "max_range" => 65535]]);
    if ($port === false) {
        $errors["port"] = "Port must be an integer between 1 and 65535.";
    }

    $slots = filter_var($values["slots"], FILTER_VALIDATE_INT,
                        ["options" => ["min_range" => 1, "max_range" => 200]]);
    if ($slots === false) {
        $errors["slots"] = "Slots must be an integer between 1 and 200.";
    }

    if (!in_array($values["status"], ["online", "offline", "fault"], true)) {
        $errors["status"] = "Invalid status.";
    }

    // Save
    if (empty($errors)) {
        try {
            Database::execute("
                INSERT INTO machines (id, location, broker_ip, port, slots, status)
                VALUES (:id, :location, :broker_ip, :port, :slots, :status)
            ", [
                ":id"        => $values["id"],
                ":location"  => $values["location"],
                ":broker_ip" => $values["broker_ip"],
                ":port"      => (int)$port,
                ":slots"     => (int)$slots,
                ":status"    => $values["status"],
            ]);

            $_SESSION["flash"] = [
                "type"    => "success",
                "message" => "Machine '{$values['id']}' registered successfully.",
            ];

            header("Location: /machines");
            exit;

        } catch (PDOException $e) {
            $errors["_db"] = "Database error — please try again.";
            error_log($e->getMessage());
        }
    }
}

ob_start();
require __DIR__ . "/../../templates/machines/form.html.php";
$content = ob_get_clean();

$title = "Register Machine — Fleet Manager";
require __DIR__ . "/../../templates/layout.php";
```

## The shared form template

```php
<?php
// templates/machines/form.html.php
// Used for both create and edit — $machine is set for edit, null for create
declare(strict_types=1);

$isEdit = isset($machine);
?>
<h1><?= $isEdit ? "Edit Machine" : "Register Machine" ?></h1>

<?php if (isset($errors["_db"])): ?>
    <div class="flash-error"><?= e($errors["_db"]) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $isEdit ? '/machines/update' : '/machines' ?>">

    <?php if ($isEdit): ?>
        <input type="hidden" name="original_id" value="<?= e($values["id"]) ?>">
    <?php endif; ?>

    <label>Machine ID
        <input type="text" name="id"
               value="<?= e($values["id"]) ?>"
               <?= $isEdit ? "readonly" : "" ?>>
        <?php if (isset($errors["id"])): ?>
            <span class="error-msg"><?= e($errors["id"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Location
        <input type="text" name="location" value="<?= e($values["location"]) ?>">
        <?php if (isset($errors["location"])): ?>
            <span class="error-msg"><?= e($errors["location"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Broker IP
        <input type="text" name="broker_ip" value="<?= e($values["broker_ip"]) ?>">
        <?php if (isset($errors["broker_ip"])): ?>
            <span class="error-msg"><?= e($errors["broker_ip"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Port
        <input type="number" name="port" value="<?= e($values["port"]) ?>"
               min="1" max="65535">
        <?php if (isset($errors["port"])): ?>
            <span class="error-msg"><?= e($errors["port"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Slots
        <input type="number" name="slots" value="<?= e($values["slots"]) ?>"
               min="1" max="200">
        <?php if (isset($errors["slots"])): ?>
            <span class="error-msg"><?= e($errors["slots"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Status
        <select name="status">
            <?php foreach (["online", "offline", "fault"] as $s): ?>
                <option value="<?= e($s) ?>" <?= $values["status"] === $s ? "selected" : "" ?>>
                    <?= e(ucfirst($s)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors["status"])): ?>
            <span class="error-msg"><?= e($errors["status"]) ?></span>
        <?php endif; ?>
    </label>

    <br>
    <button type="submit" class="btn">
        <?= $isEdit ? "Save Changes" : "Register Machine" ?>
    </button>
    <a href="/machines">Cancel</a>
</form>
```

## UPDATE — pre-filled edit form

```php
<?php
// handlers/machines/edit.php
declare(strict_types=1);

// GET — show pre-filled form
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $id      = $_GET["id"] ?? "";
    $machine = Database::fetch(
        "SELECT * FROM machines WHERE id = :id",
        [":id" => $id]
    );

    if ($machine === false) {
        http_response_code(404);
        echo "<h1>Machine not found</h1>";
        exit;
    }

    // Pre-fill $values from database row
    $values = [
        "id"        => $machine["id"],
        "location"  => $machine["location"],
        "broker_ip" => $machine["broker_ip"],
        "port"      => (string)$machine["port"],
        "slots"     => (string)$machine["slots"],
        "status"    => $machine["status"],
    ];
    $errors = [];

    ob_start();
    require __DIR__ . "/../../templates/machines/form.html.php";
    $content = ob_get_clean();

    $title = "Edit {$machine['id']} — Fleet Manager";
    require __DIR__ . "/../../templates/layout.php";
    exit;
}

// POST — process the update
$id     = trim($_POST["original_id"] ?? "");
$errors = [];
$values = [
    "id"        => $id,
    "location"  => trim($_POST["location"]  ?? ""),
    "broker_ip" => trim($_POST["broker_ip"] ?? ""),
    "port"      => trim($_POST["port"]      ?? ""),
    "slots"     => trim($_POST["slots"]     ?? ""),
    "status"    => trim($_POST["status"]    ?? ""),
];

// Validate (same rules as create, minus uniqueness check)
if ($values["location"] === "") {
    $errors["location"] = "Location is required.";
}

if (filter_var($values["broker_ip"], FILTER_VALIDATE_IP) === false) {
    $errors["broker_ip"] = "Must be a valid IP address.";
}

$port = filter_var($values["port"], FILTER_VALIDATE_INT,
                   ["options" => ["min_range" => 1, "max_range" => 65535]]);
if ($port === false) {
    $errors["port"] = "Port must be between 1 and 65535.";
}

$slots = filter_var($values["slots"], FILTER_VALIDATE_INT,
                    ["options" => ["min_range" => 1, "max_range" => 200]]);
if ($slots === false) {
    $errors["slots"] = "Slots must be between 1 and 200.";
}

if (!in_array($values["status"], ["online", "offline", "fault"], true)) {
    $errors["status"] = "Invalid status.";
}

if (empty($errors)) {
    $affected = Database::execute("
        UPDATE machines
        SET location = :location, broker_ip = :broker_ip,
            port = :port, slots = :slots, status = :status
        WHERE id = :id
    ", [
        ":location"  => $values["location"],
        ":broker_ip" => $values["broker_ip"],
        ":port"      => (int)$port,
        ":slots"     => (int)$slots,
        ":status"    => $values["status"],
        ":id"        => $id,
    ]);

    if ($affected === 0) {
        http_response_code(404);
        echo "<h1>Machine not found</h1>";
        exit;
    }

    $_SESSION["flash"] = ["type" => "success", "message" => "Machine '$id' updated."];
    header("Location: /machines");
    exit;
}

// Validation failed — redisplay form with errors
$machine = ["id" => $id];   // minimal for the template's isEdit check
ob_start();
require __DIR__ . "/../../templates/machines/form.html.php";
$content = ob_get_clean();

$title = "Edit $id — Fleet Manager";
require __DIR__ . "/../../templates/layout.php";
```

## DELETE

```php
<?php
// handlers/machines/delete.php
declare(strict_types=1);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

$id = trim($_POST["id"] ?? "");

if ($id === "") {
    $_SESSION["flash"] = ["type" => "error", "message" => "No machine ID supplied."];
    header("Location: /machines");
    exit;
}

try {
    $affected = Database::execute(
        "DELETE FROM machines WHERE id = :id",
        [":id" => $id]
    );

    if ($affected === 0) {
        $_SESSION["flash"] = ["type" => "error", "message" => "Machine '$id' not found."];
    } else {
        $_SESSION["flash"] = ["type" => "success", "message" => "Machine '$id' deleted."];
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    $_SESSION["flash"] = ["type" => "error", "message" => "Delete failed — please try again."];
}

header("Location: /machines");
exit;
```

Delete is always a POST — never a GET link. A GET link that deletes data is vulnerable to CSRF and to prefetch bots that follow links. POST with a confirmation dialog is the minimum viable protection.

---

## Today's exercise

![[Pasted image 20260602231008.png]]
Type the code yourself rather than copying it. The structure — front controller dispatching to handlers, handlers capturing template output into `$content`, templates knowing nothing about data sources — is the pattern you need in your fingers before Day 22 when you build MVC from scratch. Once you've typed it twice you'll recognize it in every framework you ever use.


The stretch goal's pagination + filtering combination is worth doing because it introduces a real SQL composition problem: building a WHERE clause dynamically based on optional filters, then reusing the same conditions for a COUNT query. Solving it cleanly with arrays of conditions and params teaches you a skill you'll need on Day 23's REST API.

Paste your code when ready — Day 13 is sessions and cookies, which adds login state on top of what you've built here.