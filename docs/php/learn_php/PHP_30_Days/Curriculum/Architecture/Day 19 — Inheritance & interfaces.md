

Day 18 gave you classes and objects. Today you learn how classes relate to each other. Inheritance lets one class build on another. Interfaces define contracts — what a class promises to do, without dictating how. These two mechanisms are what make large codebases manageable and testable.

## Inheritance — building on an existing class

```php
<?php
declare(strict_types=1);

// Base class — the general concept
class Device {
    public function __construct(
        public readonly string $id,
        public string          $location,
        protected string       $status = "offline",   // protected = accessible in subclasses
    ) {}

    public function getStatus(): string {
        return $this->status;
    }

    public function setStatus(string $status): void {
        $this->status = $status;
    }

    public function isOnline(): bool {
        return $this->status === "online";
    }

    public function describe(): string {
        return "{$this->id} at {$this->location} — {$this->status}";
    }
}

// Subclass — a more specific concept
class VendingMachine extends Device {
    public function __construct(
        string        $id,
        string        $location,
        private int   $slots  = 20,
        private int   $filled = 0,
        string        $status = "offline",
    ) {
        // parent:: calls the parent constructor
        parent::__construct($id, $location, $status);
    }

    public function fillPercent(): float {
        if ($this->slots === 0) return 0.0;
        return round($this->filled / $this->slots * 100, 1);
    }

    public function restock(int $qty): void {
        $this->filled = min($this->slots, $this->filled + $qty);
    }

    // Override parent method — add extra info
    public function describe(): string {
        return parent::describe() . " — {$this->fillPercent()}% full";
    }
}

class SensorNode extends Device {
    public function __construct(
        string                    $id,
        string                    $location,
        private readonly string   $sensorType,
        private float             $lastReading = 0.0,
        string                    $status = "offline",
    ) {
        parent::__construct($id, $location, $status);
    }

    public function updateReading(float $value): void {
        $this->lastReading = $value;
    }

    public function describe(): string {
        return parent::describe() . " — {$this->sensorType}: {$this->lastReading}";
    }
}

// Both are Devices — they share the base interface
$vm     = new VendingMachine("vend-001", "Floor 1", 20, 17, "online");
$sensor = new SensorNode("temp-003", "Roof", "temperature", 38.4, "online");

echo $vm->describe()     . "\n";   // vend-001 at Floor 1 — online — 85.0% full
echo $sensor->describe() . "\n";   // temp-003 at Roof — online — temperature: 38.4

// Both respond to Device methods
var_dump($vm->isOnline());     // true
var_dump($sensor->isOnline()); // true

// instanceof — check what a variable actually is
var_dump($vm instanceof Device);         // true
var_dump($vm instanceof VendingMachine); // true
var_dump($vm instanceof SensorNode);     // false
```

`protected` is the key visibility modifier for inheritance. `private` members are invisible to subclasses — the subclass can't read or write them even though it inherits the class. `protected` is visible within the class and all its subclasses. Choose the minimum visibility needed: `private` first, `protected` when subclasses genuinely need access.

## parent:: — calling the parent version

```php
<?php
declare(strict_types=1);

class Device {
    protected array $tags = [];

    public function setStatus(string $status): void {
        $this->status = $status;
        // log internally...
    }

    public function addTag(string $tag): void {
        $this->tags[] = $tag;
    }
}

class VendingMachine extends Device {
    private array $maintenanceLog = [];

    // Override setStatus — extend rather than replace
    public function setStatus(string $status): void {
        $previous = $this->status ?? "unknown";

        // Call parent implementation first
        parent::setStatus($status);

        // Then add subclass-specific behaviour
        if ($status === "fault") {
            $this->maintenanceLog[] = [
                "timestamp" => date("Y-m-d H:i:s"),
                "event"     => "Fault from $previous",
            ];
        }
    }
}
```

`parent::method()` calls the parent's version of the method. The pattern "call parent, then add more" is how you extend behaviour without replacing it. If you override without calling `parent::`, the parent's implementation is completely skipped — sometimes intentional, often a bug.

## Abstract classes — partial blueprints

An abstract class defines some behaviour and leaves some for subclasses to fill in. You can't instantiate an abstract class directly:

```php
<?php
declare(strict_types=1);

abstract class Device {
    public function __construct(
        public readonly string $id,
        public string          $location,
        protected string       $status = "offline",
    ) {}

    // Concrete method — implemented here, available to all subclasses
    public function isOnline(): bool {
        return $this->status === "online";
    }

    public function getStatus(): string {
        return $this->status;
    }

    // Abstract method — subclasses MUST implement this
    abstract public function describe(): string;

    // Abstract method with parameters
    abstract public function setStatus(string $status): void;

    // Template method pattern — defines the algorithm, subclasses fill in steps
    final public function report(): string {
        return sprintf(
            "[%s] %s",
            date("H:i:s"),
            $this->describe()
        );
    }
}

class VendingMachine extends Device {
    // Must implement all abstract methods — PHP will fatal error if not
    public function describe(): string {
        return "{$this->id} ({$this->location}) — {$this->status}";
    }

    public function setStatus(string $status): void {
        $allowed = ["online", "offline", "fault"];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }
        $this->status = $status;
    }
}

// new Device() would fail — abstract class cannot be instantiated
// new VendingMachine() works — all abstract methods implemented

$vm = new VendingMachine("vend-001", "Floor 1");
echo $vm->report() . "\n";   // [14:32:07] vend-001 (Floor 1) — offline
```

`final` on a method prevents subclasses from overriding it. The `report()` method above defines the output format — subclasses can change what `describe()` returns but can't change how `report()` wraps it. Use `final` when the algorithm must be consistent across all subclasses.

## Interfaces — pure contracts

An interface defines what methods a class must have, with no implementation at all. A class can implement multiple interfaces. This is PHP's answer to multiple inheritance:

```php
<?php
declare(strict_types=1);

interface Publishable {
    public function publish(): void;
    public function unpublish(): void;
    public function isPublished(): bool;
}

interface Exportable {
    public function toArray(): array;
    public function toJson(): string;
}

interface Timestampable {
    public function getCreatedAt(): \DateTimeImmutable;
    public function getUpdatedAt(): \DateTimeImmutable;
}

// A class can implement multiple interfaces
class Post implements Publishable, Exportable, Timestampable {
    private bool               $published = false;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        public readonly int    $id,
        public string          $title,
        public string          $body,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Publishable
    public function publish(): void {
        $this->published = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function unpublish(): void {
        $this->published = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isPublished(): bool {
        return $this->published;
    }

    // Exportable
    public function toArray(): array {
        return [
            "id"        => $this->id,
            "title"     => $this->title,
            "body"      => $this->body,
            "published" => $this->published,
        ];
    }

    public function toJson(): string {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    // Timestampable
    public function getCreatedAt(): \DateTimeImmutable {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable {
        return $this->updatedAt;
    }
}

$post = new Post(1, "Hello PHP", "My first post");
$post->publish();

var_dump($post->isPublished());   // true
echo $post->toJson() . "\n";

// Type hint against the interface — not the concrete class
function publishAll(Publishable ...$items): void {
    foreach ($items as $item) {
        $item->publish();
    }
}
```

The critical insight: type-hinting against `Publishable` instead of `Post` means `publishAll()` works with any class that implements `Publishable` — not just `Post`. You can add a `Machine` that implements `Publishable` tomorrow and `publishAll()` works with it immediately without any changes.

## Interface vs abstract class — when to use which

```
Interface:                          Abstract class:
──────────────────────────────────────────────────────────
Pure contract, no implementation    Partial implementation + contract
Class can implement many            Class can extend only one
Defines capability                  Defines identity
"can do" relationship               "is a" relationship

Use interface when:                 Use abstract class when:
- Multiple unrelated classes        - Classes share common code
  need the same capability          - You want to force subclasses
- You want maximum flexibility        to implement specific methods
- Different class hierarchies       - Template method pattern
  need to be treated uniformly      - Shared constructor logic
```

In practice: prefer interfaces for dependencies you type-hint against. Use abstract classes when you have real shared implementation to provide. Many real classes do both — extend an abstract class and implement one or more interfaces.

## A practical payment gateway example

```php
<?php
declare(strict_types=1);

// Interface — the contract every gateway must honour
interface PaymentGateway {
    public function charge(float $amount, string $currency, array $details): PaymentResult;
    public function refund(string $transactionId, float $amount): PaymentResult;
    public function getName(): string;
}

// Value object for results
class PaymentResult {
    public function __construct(
        public readonly bool   $success,
        public readonly string $transactionId,
        public readonly string $message,
        public readonly array  $raw = [],
    ) {}

    public static function success(string $txId, array $raw = []): static {
        return new static(true, $txId, "Payment successful", $raw);
    }

    public static function failure(string $message, array $raw = []): static {
        return new static(false, "", $message, $raw);
    }
}

// Concrete gateway A
class StripeGateway implements PaymentGateway {
    public function __construct(private readonly string $apiKey) {}

    public function charge(float $amount, string $currency, array $details): PaymentResult {
        // Real implementation would call Stripe API
        // For now — simulate
        $txId = "stripe_" . bin2hex(random_bytes(8));
        return PaymentResult::success($txId, ["gateway" => "stripe", "amount" => $amount]);
    }

    public function refund(string $transactionId, float $amount): PaymentResult {
        return PaymentResult::success("refund_" . bin2hex(random_bytes(8)));
    }

    public function getName(): string {
        return "Stripe";
    }
}

// Concrete gateway B — completely different implementation, same contract
class PayPalGateway implements PaymentGateway {
    public function __construct(
        private readonly string $clientId,
        private readonly string $secret,
    ) {}

    public function charge(float $amount, string $currency, array $details): PaymentResult {
        $txId = "paypal_" . bin2hex(random_bytes(8));
        return PaymentResult::success($txId, ["gateway" => "paypal"]);
    }

    public function refund(string $transactionId, float $amount): PaymentResult {
        return PaymentResult::success("refund_" . bin2hex(random_bytes(8)));
    }

    public function getName(): string {
        return "PayPal";
    }
}

// Order service — depends on the interface, not on a specific gateway
class OrderService {
    public function __construct(
        private readonly PaymentGateway $gateway
    ) {}

    public function checkout(float $total, array $cardDetails): void {
        $result = $this->gateway->charge($total, "EUR", $cardDetails);

        if (!$result->success) {
            throw new \RuntimeException("Payment failed: " . $result->message);
        }

        echo "Order paid via {$this->gateway->getName()}: {$result->transactionId}\n";
    }
}

// Swap gateways without touching OrderService
$stripeService = new OrderService(new StripeGateway("sk_test_..."));
$paypalService = new OrderService(new PayPalGateway("client_id", "secret"));

$stripeService->checkout(49.99, ["number" => "4242..."]);
$paypalService->checkout(49.99, ["email"  => "user@example.com"]);
```

`OrderService` is completely decoupled from which payment gateway is used. You can add a new gateway, switch gateways, or use a fake gateway in tests — none of those changes require touching `OrderService`. This is dependency injection via constructor, the most fundamental OOP design pattern.

## Interface extending interface

```php
<?php
declare(strict_types=1);

interface Readable {
    public function read(string $key): mixed;
}

interface Writable {
    public function write(string $key, mixed $value): void;
    public function delete(string $key): void;
}

// Interface can extend other interfaces
interface Cache extends Readable, Writable {
    public function has(string $key): bool;
    public function flush(): void;
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed;
}

class ArrayCache implements Cache {
    private array $store = [];

    public function read(string $key): mixed {
        return $this->store[$key] ?? null;
    }

    public function write(string $key, mixed $value): void {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void {
        unset($this->store[$key]);
    }

    public function has(string $key): bool {
        return array_key_exists($key, $this->store);
    }

    public function flush(): void {
        $this->store = [];
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed {
        if ($this->has($key)) {
            return $this->read($key);
        }
        $value = $callback();
        $this->write($key, $value);
        return $value;
    }
}

// Usage
$cache = new ArrayCache();

$machines = $cache->remember("all_machines", function(): array {
    // Expensive DB query — only runs on cache miss
    return Database::fetchAll("SELECT * FROM machines");
}, 3600);
```

---

## Today's exercise

![[Pasted image 20260603095645.png]]
Part B is the exercise that makes interfaces click permanently. The moment you write `Notifier::notify()` and realise it works identically whether the channel is email, SMS, or a log file — without a single `if` or `instanceof` check — is the moment the value of interfaces becomes concrete rather than theoretical.

The stretch goal's `FileCache` is also worth building: it's the simplest possible persistent cache, and understanding it makes Redis feel less magical when you encounter it on Day 26. Both implement the same interface, both work with `getCachedMachines()`, the only difference is where the data lives.

Paste your code when ready. Day 20 is traits and static methods — horizontal code reuse without inheritance — the last OOP building block before we move to Composer and architecture.