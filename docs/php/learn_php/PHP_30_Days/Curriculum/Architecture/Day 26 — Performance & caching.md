
Your application works. Today you make it fast. Performance problems in PHP almost always come from the same three sources: too many database queries, no caching of expensive operations, and unoptimised queries. Today you learn to measure first, then fix — and never the other way around.

## The golden rule — measure before optimising

```
Premature optimisation is the root of all evil. — Donald Knuth

The correct sequence:
1. Measure — find where time is actually spent
2. Identify — the slowest thing (usually DB queries)
3. Fix — targeted, with before/after numbers
4. Verify — the fix actually helped

Never guess. Every guess about performance is wrong at least half the time.
```

## Part 1 — Finding the slow parts

### Simple request timer

```php
<?php
declare(strict_types=1);

// Add to public/index.php — before anything else
define("REQUEST_START", microtime(true));

// Add to your layout template — at the very end
$elapsed = round((microtime(true) - REQUEST_START) * 1000, 2);
echo "<!-- {$elapsed}ms -->";
```

This shows render time in HTML comments — visible in browser dev tools source view, invisible to users. A baseline measurement costs nothing and gives you a reference point.

### Query counter — finding N+1 problems

```php
<?php
// src/Database/QueryLog.php
declare(strict_types=1);

namespace App\Database;

class QueryLog {
    private static array $queries = [];
    private static bool  $enabled = false;

    public static function enable(): void {
        self::$enabled = true;
        self::$queries = [];
    }

    public static function record(string $sql, array $params, float $timeMs): void {
        if (!self::$enabled) return;

        self::$queries[] = [
            "sql"     => $sql,
            "params"  => $params,
            "time_ms" => round($timeMs, 3),
            "trace"   => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ];
    }

    public static function getAll(): array { return self::$queries; }
    public static function count(): int    { return count(self::$queries); }

    public static function totalTime(): float {
        return array_sum(array_column(self::$queries, "time_ms"));
    }

    public static function dump(): void {
        foreach (self::$queries as $i => $q) {
            $n = $i + 1;
            echo "[$n] ({$q['time_ms']}ms) {$q['sql']}\n";
        }
        echo "Total: " . self::count() . " queries in " . self::totalTime() . "ms\n";
    }
}
```

```php
<?php
// src/Database/LoggingPdo.php — wraps PDO to record every query
declare(strict_types=1);

namespace App\Database;

class LoggingPdo {
    public function __construct(private readonly \PDO $pdo) {}

    public function prepare(string $sql): LoggingStatement {
        return new LoggingStatement($this->pdo->prepare($sql), $sql);
    }

    public function query(string $sql): LoggingStatement {
        $start = microtime(true);
        $stmt  = $this->pdo->query($sql);
        QueryLog::record($sql, [], (microtime(true) - $start) * 1000);
        return new LoggingStatement($stmt, $sql);
    }

    // Proxy all other PDO methods
    public function __call(string $name, array $args): mixed {
        return $this->pdo->$name(...$args);
    }
}

class LoggingStatement {
    public function __construct(
        private readonly \PDOStatement $stmt,
        private readonly string        $sql,
    ) {}

    public function execute(array $params = []): bool {
        $start  = microtime(true);
        $result = $this->stmt->execute($params);
        QueryLog::record($this->sql, $params, (microtime(true) - $start) * 1000);
        return $result;
    }

    public function __call(string $name, array $args): mixed {
        return $this->stmt->$name(...$args);
    }
}
```

### The N+1 query problem — the most common performance bug

```php
<?php
declare(strict_types=1);

// WRONG — N+1 queries
// 1 query to get posts + N queries to get each author
$posts = Database::fetchAll("SELECT * FROM posts WHERE status = 'published'");

foreach ($posts as $post) {
    // This runs once per post — 100 posts = 101 queries total
    $author = Database::fetch(
        "SELECT username FROM users WHERE id = :id",
        [":id" => $post["author_id"]]
    );
    echo "{$post['title']} by {$author['username']}\n";
}

// RIGHT — 1 query with JOIN
$posts = Database::fetchAll("
    SELECT p.id, p.title, p.slug, p.published_at,
           u.username AS author_name, u.id AS author_id
    FROM posts p
    JOIN users u ON u.id = p.author_id
    WHERE p.status = 'published'
    ORDER BY p.published_at DESC
");

foreach ($posts as $post) {
    echo "{$post['title']} by {$post['author_name']}\n";
}
// 1 query regardless of how many posts
```

```php
<?php
declare(strict_types=1);

// Another N+1 pattern — loading related data per item
// WRONG
$machines = $repo->findAll();
foreach ($machines as $machine) {
    $inventory = $repo->findInventory($machine->id);  // N queries
    // render...
}

// RIGHT — eager loading: load all inventory in one query
$machines  = $repo->findAll();
$machineIds = array_map(fn($m) => $m->id, $machines);

// Load all inventory for all machines in one query
$allInventory = Database::fetchAll("
    SELECT * FROM inventory
    WHERE machine_id IN (" . implode(",", array_fill(0, count($machineIds), "?")) . ")
",  $machineIds);

// Group by machine_id in PHP
$inventoryByMachine = [];
foreach ($allInventory as $row) {
    $inventoryByMachine[$row["machine_id"]][] = $row;
}

// Now attach — no additional queries
foreach ($machines as $machine) {
    $machine->inventory = $inventoryByMachine[$machine->id] ?? [];
}
```

## Part 2 — APCu: in-process cache

APCu stores data in shared memory — the same process's memory, accessible across requests on the same server. Fastest possible cache: no network, no filesystem.

```bash
sudo apt install php8.3-apcu -y
# Add to php.ini or conf.d:
echo "apc.enable_cli=1" | sudo tee /etc/php/8.3/mods-available/apcu.ini
sudo phpenmod apcu
```

```php
<?php
declare(strict_types=1);

// Basic APCu operations
apcu_store("key", "value", 300);          // store for 300 seconds
$value = apcu_fetch("key", $success);     // $success = true if found
apcu_delete("key");
apcu_clear_cache();                        // clear everything

// The pattern you'll use: cache-aside
function getCachedConfig(string $key): ?array {
    $cached = apcu_fetch("config:$key", $found);
    if ($found) return $cached;

    // Cache miss — load from DB
    $config = Database::fetch(
        "SELECT * FROM config WHERE key = :key",
        [":key" => $key]
    );

    if ($config !== false) {
        apcu_store("config:$key", $config, 600);  // cache 10 minutes
    }

    return $config ?: null;
}

// With atomic increment — useful for counters
apcu_store("request_count", 0);
$count = apcu_inc("request_count");   // atomic increment, returns new value
echo "Request #$count\n";
```

APCu limitations: data lives in one server's memory. If you have multiple PHP-FPM workers or multiple servers, each has its own APCu store. For shared caching across multiple processes or servers, use Redis.

## Part 3 — Redis: the production cache

Redis is an in-memory data structure server — persistent, shared across processes, networked.

```bash
sudo apt install redis-server php8.3-redis -y
sudo systemctl start redis-server
redis-cli ping   # should return PONG
```

```php
<?php
declare(strict_types=1);

// src/Cache/RedisCache.php
namespace App\Cache;

class RedisCache implements \App\Contracts\Cache {
    private \Redis $redis;

    public function __construct(
        string $host     = "127.0.0.1",
        int    $port     = 6379,
        int    $database = 0,
        string $prefix   = "fleet:",
    ) {
        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
        $this->redis->select($database);
        $this->redis->setOption(\Redis::OPT_PREFIX, $prefix);
    }

    public function get(string $key): mixed {
        $value = $this->redis->get($key);
        if ($value === false) return null;
        return unserialize($value);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void {
        $this->redis->setEx($key, $ttl, serialize($value));
    }

    public function delete(string $key): void {
        $this->redis->del($key);
    }

    public function has(string $key): bool {
        return (bool)$this->redis->exists($key);
    }

    public function flush(): void {
        $this->redis->flushDb();
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed {
        $cached = $this->get($key);
        if ($cached !== null) return $cached;

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // Redis-specific operations beyond the Cache interface

    public function increment(string $key, int $by = 1): int {
        return $this->redis->incrBy($key, $by);
    }

    public function decrement(string $key, int $by = 1): int {
        return $this->redis->decrBy($key, $by);
    }

    // List operations — useful for queues
    public function push(string $key, mixed $value): void {
        $this->redis->rPush($key, serialize($value));
    }

    public function pop(string $key): mixed {
        $value = $this->redis->lPop($key);
        return $value !== false ? unserialize($value) : null;
    }

    public function listLength(string $key): int {
        return $this->redis->lLen($key);
    }

    // Hash operations — efficient for objects with many fields
    public function hashSet(string $key, string $field, mixed $value): void {
        $this->redis->hSet($key, $field, serialize($value));
    }

    public function hashGet(string $key, string $field): mixed {
        $value = $this->redis->hGet($key, $field);
        return $value !== false ? unserialize($value) : null;
    }

    public function hashGetAll(string $key): array {
        $data = $this->redis->hGetAll($key);
        return array_map(fn($v) => unserialize($v), $data);
    }

    // Pub/Sub — publish a message to a channel
    public function publish(string $channel, mixed $message): void {
        $this->redis->publish($channel, json_encode($message, JSON_THROW_ON_ERROR));
    }
}
```

## Caching your fleet data

```php
<?php
declare(strict_types=1);

// src/Repositories/CachedMachineRepository.php
namespace App\Repositories;

use App\Models\Machine;
use App\Contracts\Cache;
use App\Exceptions\DeviceNotFoundException;

class CachedMachineRepository implements MachineRepositoryInterface {
    private const TTL_MACHINE   = 300;   // 5 minutes per machine
    private const TTL_ALL       = 60;    // 1 minute for the full list

    public function __construct(
        private readonly MachineRepository $inner,
        private readonly Cache             $cache,
    ) {}

    public function findById(string $id): Machine {
        $key = "machine:$id";

        $cached = $this->cache->get($key);
        if ($cached instanceof Machine) return $cached;

        $machine = $this->inner->findById($id);  // throws DeviceNotFoundException if missing
        $this->cache->set($key, $machine, self::TTL_MACHINE);
        return $machine;
    }

    public function findAll(?string $status = null): array {
        $key = "machines:all" . ($status ? ":$status" : "");

        return $this->cache->remember(
            $key,
            fn() => $this->inner->findAll($status),
            self::TTL_ALL
        );
    }

    public function save(Machine $machine): void {
        $this->inner->save($machine);

        // Invalidate affected cache keys
        $this->cache->delete("machine:{$machine->id}");
        $this->cache->delete("machines:all");
        $this->cache->delete("machines:all:{$machine->getStatus()}");
    }

    public function delete(string $id): void {
        // Fetch first to know the status (for cache invalidation)
        try {
            $machine = $this->findById($id);
            $status  = $machine->getStatus();
        } catch (DeviceNotFoundException) {
            $status = null;
        }

        $this->inner->delete($id);

        $this->cache->delete("machine:$id");
        $this->cache->delete("machines:all");
        if ($status) {
            $this->cache->delete("machines:all:$status");
        }
    }
}
```

Cache invalidation is the hardest part of caching. The rule: invalidate every cache key that contains the modified data, immediately after the write. Miss one and you serve stale data until TTL expires.

## Part 4 — Query optimisation

```php
<?php
declare(strict_types=1);

// SLOW — no index on status column
// Full table scan on every request
$machines = Database::fetchAll(
    "SELECT * FROM machines WHERE status = 'online'"
);

// FIX — add an index
// ALTER TABLE machines ADD INDEX idx_status (status);
// ALTER TABLE machines ADD INDEX idx_status_created (status, created_at);

// Verify with EXPLAIN
$plan = Database::fetchAll("EXPLAIN SELECT * FROM machines WHERE status = 'online'");
// Check: type = "ref" or "range" (good), not "ALL" (full scan, bad)
// Check: key is not NULL (index used)
var_dump($plan);

// SLOW — SELECT * fetches every column including large TEXT fields
$posts = Database::fetchAll("SELECT * FROM posts WHERE status = 'published'");

// FAST — select only what you need
$posts = Database::fetchAll("
    SELECT id, title, slug, published_at
    FROM posts
    WHERE status = 'published'
    ORDER BY published_at DESC
    LIMIT 20
");

// SLOW — COUNT(*) without index
$count = Database::fetchColumn("SELECT COUNT(*) FROM posts");

// FAST — use the primary key for count (MySQL optimises this)
$count = Database::fetchColumn("SELECT COUNT(id) FROM posts WHERE status = 'published'");
// Ensure status is indexed first
```

## Part 5 — HTTP caching

```php
<?php
declare(strict_types=1);

// src/Http/Controllers/Api/MachineApiController.php

// GET /api/machines — add HTTP cache headers
public function index(Request $request, array $params = []): Response {
    $machines = $this->fleet->getAll();
    $data     = array_map(fn($m) => $m->toArray(), $machines);

    // ETag — fingerprint of the response
    $etag = '"' . md5(json_encode($data)) . '"';

    // If client sent If-None-Match and it matches — 304 Not Modified
    $clientEtag = $request->header("If-None-Match") ?? "";
    if ($clientEtag === $etag) {
        return (new Response())
            ->status(304)
            ->header("ETag", $etag);
    }

    return $this->success($data)
        ->header("ETag", $etag)
        ->header("Cache-Control", "private, max-age=60")
        ->header("Last-Modified", gmdate("D, d M Y H:i:s") . " GMT");
}

// Public endpoints — aggressively cacheable
public function show(Request $request, array $params = []): Response {
    $machine = $this->fleet->getById($params["id"]);

    return $this->success($machine->toArray())
        ->header("Cache-Control", "public, max-age=300, stale-while-revalidate=60")
        ->header("Vary", "Accept-Encoding");
}
```

## Part 6 — A performance profiler class

```php
<?php
// src/Debug/Profiler.php
declare(strict_types=1);

namespace App\Debug;

class Profiler {
    private static array $timers    = [];
    private static array $snapshots = [];
    private static float $start;

    public static function start(): void {
        self::$start  = microtime(true);
        self::$timers = [];
        \App\Database\QueryLog::enable();
    }

    public static function mark(string $label): void {
        self::$timers[$label] = microtime(true);
    }

    public static function report(): array {
        $now      = microtime(true);
        $total    = round(($now - self::$start) * 1000, 2);
        $queries  = \App\Database\QueryLog::getAll();
        $queryMs  = \App\Database\QueryLog::totalTime();

        $marks = [];
        $prev  = self::$start;
        foreach (self::$timers as $label => $time) {
            $marks[$label] = round(($time - $prev) * 1000, 2);
            $prev = $time;
        }

        return [
            "total_ms"    => $total,
            "query_ms"    => round($queryMs, 2),
            "php_ms"      => round($total - $queryMs, 2),
            "query_count" => count($queries),
            "marks"       => $marks,
            "queries"     => array_map(fn($q) => [
                "sql"  => substr($q["sql"], 0, 100),
                "ms"   => $q["time_ms"],
            ], $queries),
        ];
    }
}
```

---

## Today's exercise

![[Pasted image 20260603101652.png]]

The before/after numbers in Part A are the most important output of today. Changing 14 queries to 1 query is a 14x improvement — that's not micro-optimisation, that's the difference between a page that loads in 200ms and one that loads in 14ms. Writing down both numbers before you fix is the habit that turns performance work into a discipline rather than guesswork.

The Redis `monitor` command tip is genuinely useful — watching commands fly by in real time as you click around your app makes cache hits and misses immediately visible in a way that log files don't. Run it during Part B and leave it open while you test.

Day 27 is queues and background jobs — the natural next step from Redis, since Redis is the most common queue backend for PHP applications. Paste your code when ready.