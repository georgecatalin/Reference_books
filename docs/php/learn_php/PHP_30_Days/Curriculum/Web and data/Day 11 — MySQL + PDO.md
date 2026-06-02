
Every real application needs persistent data that survives beyond a single request. Files work for simple cases — you've already used them. A database gives you structured storage, concurrent access, querying, relationships, and transactions. Today you connect PHP to MySQL using PDO — the right abstraction layer.

## Why PDO, not mysqli

PHP has two MySQL APIs: `mysqli` and `PDO`. Use PDO. It works with multiple database engines (MySQL, PostgreSQL, SQLite) using the same API, has a cleaner interface, and its prepared statement syntax is less error-prone. If you ever need to switch from MySQL to PostgreSQL, PDO makes it a configuration change, not a rewrite.

## Step 1 — Install MySQL and create a database

```bash
# Install MySQL server
sudo apt install mysql-server -y

# Secure the installation
sudo mysql_secure_installation

# Log in as root
sudo mysql -u root -p

# Inside MySQL shell:
CREATE DATABASE fleet_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleet_user'@'localhost' IDENTIFIED BY 'strongpassword';
GRANT ALL PRIVILEGES ON fleet_db.* TO 'fleet_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Always use `utf8mb4`, not `utf8`. MySQL's `utf8` is a 3-byte subset that can't store emoji or certain Unicode characters. `utf8mb4` is the real thing.

## Step 2 — Connecting with PDO

```php
<?php
declare(strict_types=1);

function createConnection(): PDO {
    $dsn = "mysql:host=127.0.0.1;port=3306;dbname=fleet_db;charset=utf8mb4";
    $user = "fleet_user";
    $pass = "strongpassword";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // arrays, not objects
        PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
        PDO::ATTR_PERSISTENT         => false,                    // no connection pooling yet
    ];

    try {
        return new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // Never expose connection details in production
        throw new RuntimeException("Database connection failed", 0, $e);
    }
}

$pdo = createConnection();
echo "Connected.\n";
```

Three options matter most:

- `ERRMODE_EXCEPTION` — PDO throws `PDOException` on failure instead of returning `false`. Always set this.
- `FETCH_ASSOC` — rows come back as associative arrays (`$row["name"]`) not objects or numeric arrays.
- `EMULATE_PREPARES => false` — use real prepared statements in the MySQL driver, not PHP-emulated ones. Real prepared statements are slightly more secure and correctly handle edge cases with certain data types.

## Step 3 — Creating tables

```php
<?php
declare(strict_types=1);

$pdo = createConnection();

// Create the machines table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS machines (
        id          VARCHAR(30)     NOT NULL PRIMARY KEY,
        location    VARCHAR(100)    NOT NULL,
        broker_ip   VARCHAR(45)     NOT NULL,
        port        SMALLINT UNSIGNED NOT NULL DEFAULT 1883,
        slots       TINYINT UNSIGNED  NOT NULL DEFAULT 20,
        status      ENUM('online','offline','fault') NOT NULL DEFAULT 'offline',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Create inventory table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS inventory (
        id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
        machine_id  VARCHAR(30)     NOT NULL,
        slot        TINYINT UNSIGNED NOT NULL,
        item_name   VARCHAR(100)    NOT NULL,
        quantity    TINYINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Tables created.\n";
```

`exec()` is for statements that don't return rows — DDL (CREATE, ALTER, DROP) and DML without results (DELETE without SELECT). For queries that return data, use `query()` or prepared statements.

## Step 4 — INSERT with prepared statements

This is the most important pattern of the day. Never concatenate user data into a SQL string:

```php
<?php
declare(strict_types=1);

$pdo = createConnection();

// WRONG — SQL injection waiting to happen
// $id = $_POST["id"];  // attacker sends: vend-001'; DROP TABLE machines; --
// $pdo->exec("INSERT INTO machines (id) VALUES ('$id')");

// RIGHT — prepared statement, always
$stmt = $pdo->prepare("
    INSERT INTO machines (id, location, broker_ip, port, slots, status)
    VALUES (:id, :location, :broker_ip, :port, :slots, :status)
");

$stmt->execute([
    ":id"        => "vend-001",
    ":location"  => "Floor 1 — East Wing",
    ":broker_ip" => "192.168.1.100",
    ":port"      => 1883,
    ":slots"     => 20,
    ":status"    => "online",
]);

echo "Inserted machine. Last ID: " . $pdo->lastInsertId() . "\n";
// Note: lastInsertId() returns "" for tables with non-auto-increment PKs
// Our machines table uses a string PK, so we already know the id

// Insert multiple rows efficiently
$machines = [
    ["vend-002", "Floor 2 — Canteen",  "192.168.1.101", 1883, 15, "online"],
    ["vend-003", "Lobby",              "192.168.1.102", 1883, 20, "fault"],
    ["vend-004", "Basement — Storage", "192.168.1.103", 8883, 10, "offline"],
];

$stmt = $pdo->prepare("
    INSERT INTO machines (id, location, broker_ip, port, slots, status)
    VALUES (?, ?, ?, ?, ?, ?)
");

foreach ($machines as $m) {
    $stmt->execute($m);   // prepare once, execute many — efficient
}

echo "Inserted " . count($machines) . " more machines.\n";
```

Two placeholder styles: named (`:id`, `:location`) and positional (`?`). Named placeholders are more readable for complex queries. Positional are fine for simple inserts where you're passing an array. Pick one style per query — don't mix them.

## Step 5 — SELECT queries

```php
<?php
declare(strict_types=1);

$pdo = createConnection();

// Fetch all rows
$stmt = $pdo->query("SELECT * FROM machines ORDER BY created_at DESC");
$machines = $stmt->fetchAll();   // returns array of associative arrays

foreach ($machines as $m) {
    echo "{$m['id']} — {$m['location']} — {$m['status']}\n";
}

// Fetch with a WHERE clause — always use prepared statements
$status = "online";
$stmt   = $pdo->prepare("SELECT * FROM machines WHERE status = :status ORDER BY id");
$stmt->execute([":status" => $status]);
$online = $stmt->fetchAll();

echo count($online) . " machines online\n";

// Fetch a single row — fetchAll then [0] is wasteful
$stmt = $pdo->prepare("SELECT * FROM machines WHERE id = :id LIMIT 1");
$stmt->execute([":id" => "vend-001"]);
$machine = $stmt->fetch();   // returns one row or false

if ($machine === false) {
    echo "Machine not found\n";
} else {
    echo "Found: {$machine['location']}\n";
}

// Fetch a single value — fetchColumn()
$stmt = $pdo->prepare("SELECT COUNT(*) FROM machines WHERE status = :status");
$stmt->execute([":status" => "online"]);
$count = (int)$stmt->fetchColumn();
echo "Online machines: $count\n";

// Fetch as specific class — PDO::FETCH_CLASS
// We'll use this more in OOP chapters — skip for now
```

## Step 6 — UPDATE and DELETE

```php
<?php
declare(strict_types=1);

$pdo = createConnection();

// UPDATE
$stmt = $pdo->prepare("
    UPDATE machines
    SET status = :status, updated_at = NOW()
    WHERE id = :id
");

$stmt->execute([":status" => "fault", ":id" => "vend-003"]);

// rowCount() tells you how many rows were affected
echo "Updated: " . $stmt->rowCount() . " row(s)\n";

// Check rowCount to detect "update with no match"
if ($stmt->rowCount() === 0) {
    echo "Warning: no machine found with that id\n";
}

// UPDATE multiple columns conditionally
$updates = ["status" => "online", "port" => 8883];
$id      = "vend-002";

// Build the SET clause dynamically — safely
$setClauses = array_map(fn(string $col): string => "$col = :$col", array_keys($updates));
$sql        = "UPDATE machines SET " . implode(", ", $setClauses) . " WHERE id = :id";

$params        = $updates;
$params["id"]  = $id;

$pdo->prepare($sql)->execute($params);

// DELETE
$stmt = $pdo->prepare("DELETE FROM machines WHERE id = :id");
$stmt->execute([":id" => "vend-004"]);
echo "Deleted: " . $stmt->rowCount() . " row(s)\n";
```

## Step 7 — Transactions

When multiple queries must all succeed or all fail together, use a transaction:

```php
<?php
declare(strict_types=1);

$pdo = createConnection();

// Transfer a slot's inventory between machines — both updates must succeed
try {
    $pdo->beginTransaction();

    // Deduct from source
    $stmt = $pdo->prepare("
        UPDATE inventory
        SET quantity = quantity - :qty
        WHERE machine_id = :machine AND slot = :slot AND quantity >= :qty
    ");
    $stmt->execute([":qty" => 5, ":machine" => "vend-001", ":slot" => 3]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("Insufficient quantity or machine/slot not found");
    }

    // Add to destination
    $stmt = $pdo->prepare("
        UPDATE inventory
        SET quantity = quantity + :qty
        WHERE machine_id = :machine AND slot = :slot
    ");
    $stmt->execute([":qty" => 5, ":machine" => "vend-002", ":slot" => 1]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("Destination machine/slot not found");
    }

    $pdo->commit();
    echo "Transfer complete\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "Transfer failed, rolled back: " . $e->getMessage() . "\n";
}
```

Transaction rules: `beginTransaction()` → your queries → `commit()` on success, `rollBack()` in the catch. Always put `rollBack()` in a catch block, not finally — you only roll back on failure.

## Step 8 — A clean database wrapper

Repeating `prepare/execute` everywhere gets verbose. A thin wrapper makes it readable:

```php
<?php
declare(strict_types=1);

class Database {
    private static ?PDO $instance = null;

    public static function connection(): PDO {
        if (self::$instance === null) {
            $dsn  = "mysql:host=127.0.0.1;dbname=fleet_db;charset=utf8mb4";
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, "fleet_user", "strongpassword", $opts);
            } catch (PDOException $e) {
                throw new RuntimeException("DB connection failed", 0, $e);
            }
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetch(string $sql, array $params = []): array|false {
        return self::query($sql, $params)->fetch();
    }

    public static function fetchColumn(string $sql, array $params = []): mixed {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): int {
        return self::query($sql, $params)->rowCount();
    }
}

// Now every query is a one-liner
$machines = Database::fetchAll(
    "SELECT * FROM machines WHERE status = :status",
    [":status" => "online"]
);

$machine = Database::fetch(
    "SELECT * FROM machines WHERE id = :id",
    [":id" => "vend-001"]
);

$count = (int)Database::fetchColumn(
    "SELECT COUNT(*) FROM machines WHERE status = :status",
    [":status" => "fault"]
);

Database::execute(
    "UPDATE machines SET status = :status WHERE id = :id",
    [":status" => "online", ":id" => "vend-003"]
);
```

The singleton pattern here (`self::$instance`) ensures you don't create a new database connection on every query — one connection per request, reused throughout. This is not a substitute for a proper connection pool (which you'd need in a high-traffic application), but it's the right approach for a single-process PHP request.

---

## Today's exercise

![[Pasted image 20260602230623.png]]
The stretch goal's event log table is the foundation for everything you'll build in Days 12–14. A machine that records every status change with a timestamp is the beginning of a real audit trail — exactly what a fleet management system needs. Build it now and Day 12's CRUD application slots right into the same schema.

Paste your code when ready. Day 12 is CRUD — you'll build a complete web interface over this same database.