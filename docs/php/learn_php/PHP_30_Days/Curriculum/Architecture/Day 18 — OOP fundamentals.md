
You've been writing procedural PHP — functions, arrays, `require_once`, global state. It works, and you've shipped real features with it. Today you learn OOP not as a replacement for what you know but as a better way to organise it. By the end of Day 18 you'll understand why every PHP framework is built on classes, and the code you wrote in Days 9–17 will make more sense in retrospect.

## The mental model — what a class actually is

A class is a blueprint. An object is a thing built from that blueprint. The blueprint defines what data the thing holds (properties) and what it can do (methods). Multiple objects can be created from the same blueprint, each with their own independent data.

```
Blueprint (class):  Machine
  - has an id
  - has a status
  - can go online
  - can report its status

Object 1 (instance): vend-001, offline
Object 2 (instance): vend-002, online
Object 3 (instance): vend-003, fault

Each object is independent — changing vend-001's status doesn't affect vend-002.
```

Compare this to your procedural approach: arrays of machine data passed between functions. Classes package the data and the functions that operate on it into one unit. That unit has a name, a contract, and a clear boundary.

## Defining a class

```php
<?php
declare(strict_types=1);

class Machine {
    // Properties — data the object holds
    public string  $id;
    public string  $location;
    public string  $status;
    public int     $slots;
    public int     $filled;

    // Constructor — runs when you create an object with new
    public function __construct(
        string $id,
        string $location,
        string $status = "offline",
        int    $slots  = 20,
        int    $filled = 0
    ) {
        $this->id       = $id;
        $this->location = $location;
        $this->status   = $status;
        $this->slots    = $slots;
        $this->filled   = $filled;
    }

    // Methods — behaviour the object has
    public function fillPercent(): float {
        if ($this->slots === 0) return 0.0;
        return round($this->filled / $this->slots * 100, 1);
    }

    public function isOnline(): bool {
        return $this->status === "online";
    }

    public function needsRestock(int $threshold = 30): bool {
        return $this->fillPercent() < $threshold;
    }

    public function describe(): string {
        return sprintf(
            "%s (%s) — %s — %.1f%% full",
            $this->id,
            $this->location,
            $this->status,
            $this->fillPercent()
        );
    }
}

// Creating objects
$m1 = new Machine("vend-001", "Floor 1", "online", 20, 17);
$m2 = new Machine("vend-002", "Lobby",   "fault",  15, 2);

echo $m1->describe() . "\n";   // vend-001 (Floor 1) — online — 85.0% full
echo $m2->describe() . "\n";   // vend-002 (Lobby) — fault — 13.3% full

var_dump($m1->needsRestock());   // false
var_dump($m2->needsRestock());   // true

// Objects are independent
$m1->status = "offline";
echo $m2->status . "\n";   // still "fault" — m2 is unaffected
```

`$this` refers to the current object — the specific instance the method is running on. When you call `$m1->describe()`, inside that method `$this` is `$m1`. When you call `$m2->describe()`, `$this` is `$m2`.

## PHP 8 constructor promotion — the shorthand you'll use daily

Typing property declarations and constructor assignments separately is verbose. PHP 8 lets you do both in one place:

```php
<?php
declare(strict_types=1);

class Machine {
    // Constructor promotion — declares and assigns in one shot
    public function __construct(
        public string $id,
        public string $location,
        public string $status   = "offline",
        public int    $slots    = 20,
        public int    $filled   = 0,
    ) {}   // body is empty — promotion does all the work

    public function fillPercent(): float {
        if ($this->slots === 0) return 0.0;
        return round($this->filled / $this->slots * 100, 1);
    }

    public function isOnline(): bool {
        return $this->status === "online";
    }

    public function needsRestock(int $threshold = 30): bool {
        return $this->fillPercent() < $threshold;
    }

    public function describe(): string {
        return sprintf(
            "%s (%s) — %s — %.1f%% full",
            $this->id,
            $this->location,
            $this->status,
            $this->fillPercent()
        );
    }
}

$m = new Machine("vend-001", "Floor 1", "online", 20, 17);
echo $m->describe() . "\n";
```

The `public` (or `private`/`protected`) keyword before the parameter name is the signal — PHP sees it and automatically creates the property and assigns it. Empty constructor body is intentional and correct.

## Access modifiers — public, protected, private

```php
<?php
declare(strict_types=1);

class Machine {
    public function __construct(
        public readonly string $id,       // public — readable from anywhere, readonly
        public string          $location, // public — readable and writable from anywhere
        private string         $status,   // private — only accessible inside this class
        private int            $slots,
        private int            $filled,
    ) {}

    // Public interface — what the outside world can call
    public function getStatus(): string {
        return $this->status;
    }

    public function setStatus(string $status): void {
        $allowed = ["online", "offline", "fault"];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }
        $this->status = $status;
    }

    public function fillPercent(): float {
        if ($this->slots === 0) return 0.0;
        return round($this->filled / $this->slots * 100, 1);
    }

    public function restock(int $quantity): void {
        $this->filled = min($this->slots, $this->filled + $quantity);
    }

    // Private method — internal detail, not part of the public interface
    private function validateQuantity(int $qty): bool {
        return $qty > 0 && $qty <= $this->slots;
    }
}

$m = new Machine("vend-001", "Floor 1", "online", 20, 10);

// Public access works
echo $m->id . "\n";              // vend-001
echo $m->location . "\n";        // Floor 1
echo $m->getStatus() . "\n";     // online

// Controlled mutation through method
$m->setStatus("fault");

// Private access fails
// echo $m->status;              // Fatal error: Cannot access private property
// $m->status = "online";        // Fatal error

// readonly prevents mutation even from inside the class after construction
// $m->id = "vend-999";          // Fatal error: Cannot modify readonly property
```

The rule of thumb: start everything `private`. Make it `public` only when something outside the class genuinely needs to read or change it. This keeps your class's internal details from leaking into the rest of the codebase — if you later change how `$status` is stored, you only have to update the class, not every place that touched `$status` directly.

`readonly` (PHP 8.1) means the property can only be written in the constructor. After that it's immutable. Use it for identity fields like `id` — a machine's ID should never change after creation.

## Method chaining — fluent interface

When methods return `$this`, you can chain calls:

```php
<?php
declare(strict_types=1);

class MachineQuery {
    private array  $conditions = [];
    private array  $params     = [];
    private ?int   $limitValue = null;
    private string $orderBy    = "id";

    public function whereStatus(string $status): static {
        $this->conditions[] = "status = :status";
        $this->params[":status"] = $status;
        return $this;
    }

    public function whereLocation(string $location): static {
        $this->conditions[] = "location LIKE :location";
        $this->params[":location"] = "%$location%";
        return $this;
    }

    public function limit(int $n): static {
        $this->limitValue = $n;
        return $this;
    }

    public function orderBy(string $column): static {
        // Whitelist — never interpolate user input into ORDER BY
        $allowed = ["id", "location", "status", "created_at"];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid column: $column");
        }
        $this->orderBy = $column;
        return $this;
    }

    public function toSql(): string {
        $sql = "SELECT * FROM machines";
        if (!empty($this->conditions)) {
            $sql .= " WHERE " . implode(" AND ", $this->conditions);
        }
        $sql .= " ORDER BY {$this->orderBy}";
        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }
        return $sql;
    }

    public function get(): array {
        return Database::fetchAll($this->toSql(), $this->params);
    }
}

// Fluent interface — each method returns the same object
$machines = (new MachineQuery())
    ->whereStatus("online")
    ->whereLocation("Floor")
    ->orderBy("location")
    ->limit(10)
    ->get();
```

This is a simplified version of what Laravel's Eloquent query builder does. You've now built the underlying idea from scratch.

Return type `static` instead of `self` is important for inheritance — `static` refers to the actual class being instantiated, which matters when a subclass calls a chained method. Use `static` for fluent builder methods.

## Magic methods — the ones worth knowing now

```php
<?php
declare(strict_types=1);

class Machine {
    public function __construct(
        public readonly string $id,
        public string          $location,
        private string         $status = "offline",
        private int            $slots  = 20,
        private int            $filled = 0,
    ) {}

    // __toString — called when object is used as a string
    public function __toString(): string {
        return "{$this->id} ({$this->location})";
    }

    // __get — called when reading an inaccessible/nonexistent property
    public function __get(string $name): mixed {
        return match($name) {
            "fillPercent" => $this->slots > 0
                ? round($this->filled / $this->slots * 100, 1)
                : 0.0,
            "isEmpty"     => $this->filled === 0,
            "isFull"      => $this->filled >= $this->slots,
            default       => throw new \RuntimeException("Unknown property: $name"),
        };
    }

    // __debugInfo — controls what var_dump() shows
    public function __debugInfo(): array {
        return [
            "id"          => $this->id,
            "location"    => $this->location,
            "status"      => $this->status,
            "fill_pct"    => $this->slots > 0
                ? round($this->filled / $this->slots * 100, 1) . "%"
                : "N/A",
        ];
    }
}

$m = new Machine("vend-001", "Floor 1", "online", 20, 17);

echo $m . "\n";                    // vend-001 (Floor 1) — via __toString
echo $m->fillPercent . "\n";       // 85 — via __get
var_dump($m->isEmpty);             // false — via __get
var_dump($m);                      // uses __debugInfo — clean output
```

Don't overuse magic methods — they make code harder to reason about because the behavior is implicit. `__toString` is universally useful. `__get` and `__set` are powerful but should be used sparingly and documented clearly.

## Static methods and properties

Static members belong to the class itself, not to any instance:

```php
<?php
declare(strict_types=1);

class MachineStatus {
    // Class constant — shared, immutable
    public const ONLINE  = "online";
    public const OFFLINE = "offline";
    public const FAULT   = "fault";

    public const ALL = [self::ONLINE, self::OFFLINE, self::FAULT];

    // Static property — one value shared across all instances
    private static int $instanceCount = 0;

    public function __construct(
        public readonly string $id,
        private string         $status = self::OFFLINE,
    ) {
        self::$instanceCount++;
    }

    // Static factory method — alternative constructor
    public static function online(string $id): static {
        return new static($id, self::ONLINE);
    }

    public static function fault(string $id): static {
        return new static($id, self::FAULT);
    }

    // Static method — no $this available
    public static function isValid(string $status): bool {
        return in_array($status, self::ALL, true);
    }

    public static function getInstanceCount(): int {
        return self::$instanceCount;
    }
}

// Using constants
echo MachineStatus::ONLINE . "\n";   // online
var_dump(MachineStatus::isValid("fault"));   // true

// Factory methods — readable alternative to new
$m1 = MachineStatus::online("vend-001");
$m2 = MachineStatus::fault("vend-002");

echo MachineStatus::getInstanceCount() . "\n";   // 2
```

Static factory methods (`online()`, `fault()`) are named constructors — they make the intent clear without requiring the caller to know the internal parameter order. `new Machine("vend-001", "online")` is less readable than `Machine::online("vend-001")`.

## Putting it together — a Product class

```php
<?php
declare(strict_types=1);

class Product {
    public function __construct(
        public readonly int    $id,
        public string          $name,
        private float          $price,
        private int            $stock,
        public readonly string $sku,
    ) {
        if ($price < 0.0) {
            throw new \InvalidArgumentException("Price cannot be negative: $price");
        }
        if ($stock < 0) {
            throw new \InvalidArgumentException("Stock cannot be negative: $stock");
        }
    }

    public function getPrice(): float {
        return $this->price;
    }

    public function setPrice(float $price): void {
        if ($price < 0.0) {
            throw new \InvalidArgumentException("Price cannot be negative.");
        }
        $this->price = $price;
    }

    public function getStock(): int {
        return $this->stock;
    }

    public function isInStock(): bool {
        return $this->stock > 0;
    }

    public function sell(int $quantity = 1): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException("Quantity must be positive.");
        }
        if ($quantity > $this->stock) {
            throw new \RuntimeException("Insufficient stock: have {$this->stock}, requested $quantity.");
        }
        $this->stock -= $quantity;
    }

    public function restock(int $quantity): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException("Quantity must be positive.");
        }
        $this->stock += $quantity;
    }

    public function formattedPrice(string $currency = "EUR"): string {
        return number_format($this->price, 2) . " $currency";
    }

    public function __toString(): string {
        return "{$this->sku}: {$this->name} @ {$this->formattedPrice()} ({$this->stock} in stock)";
    }

    public function toArray(): array {
        return [
            "id"    => $this->id,
            "name"  => $this->name,
            "price" => $this->price,
            "stock" => $this->stock,
            "sku"   => $this->sku,
        ];
    }
}

// Usage
$p = new Product(1, "Cable tie pack", 2.49, 150, "ACC-001");
echo $p . "\n";   // ACC-001: Cable tie pack @ 2.49 EUR (150 in stock)

$p->sell(10);
echo $p->getStock() . "\n";   // 140

try {
    $p->sell(200);
} catch (\RuntimeException $e) {
    echo $e->getMessage() . "\n";   // Insufficient stock: have 140, requested 200.
}
```

Notice the validation in the constructor. A `Product` can only exist in a valid state — negative price or stock is rejected at creation time. This is an invariant: a rule that is always true for every instance of the class. Enforcing invariants in the constructor means you never have to defensively check for invalid states throughout the rest of your code.

---

## Today's exercise

![[Pasted image 20260603095315.png]]
Part B is the one to spend the most time on. Converting your Day 13 procedural cart (arrays + functions) into `Cart` and `CartItem` classes is the clearest possible demonstration of what OOP actually buys you — the cart enforces its own rules, the item knows its own line total, and the code that uses them reads like English. Compare the two versions side by side when you're done.

The stretch goal's `MachineQuery` builder is a preview of Day 22's full MVC architecture and Day 29's Laravel Eloquent. Once you've built a query builder from scratch, Eloquent's `Machine::where('status', 'online')->limit(5)->get()` is immediately readable — you know exactly what's happening underneath.

Paste your code when ready. Day 19 is inheritance and interfaces — the contracts that make large OOP codebases manageable.