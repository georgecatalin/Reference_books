

Day 18 gave you classes. Day 19 gave you inheritance (vertical reuse — parent to child) and interfaces (contracts). Today you get traits — horizontal reuse. A trait lets you share concrete method implementations across classes that don't share an inheritance chain. It's the answer to "I need this behaviour in two classes that are completely unrelated."

## The problem traits solve

```php
<?php
declare(strict_types=1);

// Both of these classes need timestamp tracking
// But they can't share a common ancestor — they're unrelated
class Post {
    // needs: createdAt, updatedAt, touchUpdatedAt()
}

class Machine {
    // needs: createdAt, updatedAt, touchUpdatedAt()
}

// Option 1: copy-paste the code — maintainability nightmare
// Option 2: common base class — forces an "is a" relationship that isn't true
//           (a Machine IS NOT a Post and vice versa)
// Option 3: trait — inject the shared behaviour into both independently
```

A `Post` is not a `Machine`. They shouldn't share a parent class. But they can both use a `Timestampable` trait.

## Defining and using a trait

```php
<?php
declare(strict_types=1);

trait Timestampable {
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    // Called automatically when the trait is first used —
    // but only if the class doesn't define its own __construct
    // (We'll handle constructor interaction explicitly)

    protected function initTimestamps(): void {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable {
        return $this->updatedAt;
    }

    public function getAge(): string {
        $diff    = $this->createdAt->diff(new \DateTimeImmutable());
        return match(true) {
            $diff->days === 0 => "today",
            $diff->days === 1 => "yesterday",
            $diff->days < 7   => "{$diff->days} days ago",
            $diff->days < 30  => (int)($diff->days / 7) . " weeks ago",
            default           => $this->createdAt->format("Y-m-d"),
        };
    }
}

trait SoftDeletable {
    private ?\DateTimeImmutable $deletedAt = null;

    public function softDelete(): void {
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function restore(): void {
        $this->deletedAt = null;
    }

    public function isDeleted(): bool {
        return $this->deletedAt !== null;
    }

    public function getDeletedAt(): ?\DateTimeImmutable {
        return $this->deletedAt;
    }
}

trait HasSlug {
    private string $slug = "";

    public function setSlug(string $title): void {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $this->slug = trim(preg_replace('/-+/', '-', $slug), '-');
    }

    public function getSlug(): string {
        return $this->slug;
    }
}

// A class uses multiple traits with `use`
class Post {
    use Timestampable, SoftDeletable, HasSlug;

    public function __construct(
        public readonly int    $id,
        public string          $title,
        public string          $body,
    ) {
        $this->initTimestamps();   // call trait initialiser explicitly
        $this->setSlug($title);
    }
}

class Machine {
    use Timestampable, SoftDeletable;

    public function __construct(
        public readonly string $id,
        public string          $location,
    ) {
        $this->initTimestamps();
    }
}

// Both classes now have timestamps and soft delete
$post = new Post(1, "Hello PHP OOP", "Learning traits today");
echo $post->getSlug()  . "\n";   // hello-php-oop
echo $post->getAge()   . "\n";   // today
sleep(1);
$post->touch();
echo $post->getUpdatedAt()->format("H:i:s") . "\n";

$post->softDelete();
var_dump($post->isDeleted());   // true
$post->restore();
var_dump($post->isDeleted());   // false

$machine = new Machine("vend-001", "Floor 1");
echo $machine->getAge() . "\n";  // today
$machine->softDelete();
var_dump($machine->isDeleted()); // true
```

## Trait conflict resolution

When two traits define a method with the same name, PHP forces you to resolve the conflict explicitly:

```php
<?php
declare(strict_types=1);

trait Logger {
    public function log(string $message): void {
        echo "[LOG] $message\n";
    }

    public function describe(): string {
        return "Logger trait";
    }
}

trait Auditor {
    public function audit(string $action): void {
        echo "[AUDIT] $action at " . date("H:i:s") . "\n";
    }

    public function describe(): string {
        return "Auditor trait";
    }
}

class Service {
    use Logger, Auditor {
        // Both traits have describe() — resolve the conflict
        Logger::describe   insteadof Auditor;   // use Logger's version
        Auditor::describe  as describeAudit;    // alias Auditor's version under new name
    }
}

$s = new Service();
echo $s->describe()      . "\n";   // Logger trait
echo $s->describeAudit() . "\n";   // Auditor trait
$s->log("Service started");
$s->audit("config_loaded");
```

Conflict resolution is explicit and verbose by design — PHP forces you to make a decision rather than silently picking one. If you're resolving conflicts often, that's a signal your traits are too large or overlapping too much.

## Abstract methods in traits

A trait can declare abstract methods — forcing any class that uses the trait to implement them:

```php
<?php
declare(strict_types=1);

trait Validatable {
    // Trait declares abstract — using class must implement
    abstract protected function rules(): array;

    public function validate(array $data): array {
        $errors = [];

        foreach ($this->rules() as $field => $rule) {
            $value = $data[$field] ?? null;

            if (str_contains($rule, "required") && empty($value)) {
                $errors[$field] = "$field is required.";
                continue;
            }

            if (str_contains($rule, "email") && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "$field must be a valid email.";
            }

            if (preg_match('/min:(\d+)/', $rule, $m) && strlen((string)$value) < (int)$m[1]) {
                $errors[$field] = "$field must be at least {$m[1]} characters.";
            }

            if (preg_match('/max:(\d+)/', $rule, $m) && strlen((string)$value) > (int)$m[1]) {
                $errors[$field] = "$field must be at most {$m[1]} characters.";
            }
        }

        return $errors;
    }
}

class RegistrationForm {
    use Validatable;

    protected function rules(): array {
        return [
            "username" => "required|min:3|max:30",
            "email"    => "required|email",
            "password" => "required|min:8",
        ];
    }
}

class MachineForm {
    use Validatable;

    protected function rules(): array {
        return [
            "id"       => "required|min:3|max:20",
            "location" => "required|max:100",
        ];
    }
}

$form   = new RegistrationForm();
$errors = $form->validate([
    "username" => "ge",
    "email"    => "not-an-email",
    "password" => "secret",
]);

var_dump($errors);
// ["username" => "username must be at least 3 characters.",
//  "email"    => "email must be a valid email.",
//  "password" => "password must be at least 8 characters."]
```

## Traits accessing class state

A trait can use `$this` and access class properties — including ones defined by the class, not the trait. This is powerful but requires discipline:

```php
<?php
declare(strict_types=1);

trait Observable {
    private array $observers  = [];
    private array $eventQueue = [];

    public function on(string $event, callable $handler): void {
        $this->observers[$event][] = $handler;
    }

    protected function emit(string $event, mixed $data = null): void {
        foreach ($this->observers[$event] ?? [] as $handler) {
            $handler($data, $this);
        }
    }
}

class Machine {
    use Observable;

    public function __construct(
        public readonly string $id,
        private string         $status = "offline",
    ) {}

    public function setStatus(string $status): void {
        $previous      = $this->status;
        $this->status  = $status;

        // emit() is from the trait — uses $this which is the Machine instance
        $this->emit("status_changed", [
            "from" => $previous,
            "to"   => $status,
            "id"   => $this->id,
        ]);

        if ($status === "fault") {
            $this->emit("fault", ["id" => $this->id]);
        }
    }

    public function getStatus(): string {
        return $this->status;
    }
}

$machine = new Machine("vend-001", "online");

// Subscribe to events
$machine->on("status_changed", function(array $data): void {
    echo "[EVENT] {$data['id']}: {$data['from']} → {$data['to']}\n";
});

$machine->on("fault", function(array $data): void {
    echo "[ALERT] Machine {$data['id']} has a fault — notify maintenance!\n";
});

$machine->setStatus("fault");
// [EVENT] vend-001: online → fault
// [ALERT] Machine vend-001 has a fault — notify maintenance!
```

## Static methods — the right uses

Static methods belong to the class, not to any instance. They don't have access to `$this`. Use them deliberately — not as a way to avoid OOP:

```php
<?php
declare(strict_types=1);

class Temperature {
    private function __construct(
        private readonly float  $value,
        private readonly string $unit,
    ) {}

    // Static factory methods — the main legitimate use case
    public static function celsius(float $value): static {
        return new static($value, "C");
    }

    public static function fahrenheit(float $value): static {
        return new static($value, "F");
    }

    public static function kelvin(float $value): static {
        if ($value < 0) {
            throw new \InvalidArgumentException("Kelvin cannot be negative.");
        }
        return new static($value, "K");
    }

    // Conversion methods
    public function toCelsius(): static {
        return match($this->unit) {
            "C" => $this,
            "F" => static::celsius(($this->value - 32) * 5 / 9),
            "K" => static::celsius($this->value - 273.15),
        };
    }

    public function toFahrenheit(): static {
        $celsius = $this->toCelsius();
        return static::fahrenheit($celsius->value * 9 / 5 + 32);
    }

    public function getValue(): float {
        return $this->value;
    }

    public function __toString(): string {
        return round($this->value, 2) . "°{$this->unit}";
    }
}

// Private constructor forces use of named factories
$body  = Temperature::celsius(37.0);
$boil  = Temperature::fahrenheit(212.0);
$zero  = Temperature::kelvin(0.0);

echo $body                . "\n";   // 37°C
echo $body->toFahrenheit()  . "\n";   // 98.6°F
echo $boil->toCelsius()     . "\n";   // 100°C
echo $zero->toCelsius()     . "\n";   // -273.15°C
```

Private constructor + static factories is a common pattern for value objects — types that represent a value with built-in validation and conversion. The user can only create a `Temperature` through the named factories, never with `new Temperature(...)` directly.

## When NOT to use static

```php
<?php
declare(strict_types=1);

// WRONG — static used to avoid proper dependency injection
class OrderService {
    public function process(array $items): void {
        // Static call creates hidden dependency — can't be swapped for testing
        Database::execute("INSERT INTO orders ...");
        Logger::log("Order created");
        EmailService::send("...");
    }
}

// RIGHT — dependencies injected, swappable, testable
class OrderService {
    public function __construct(
        private readonly PDO             $db,
        private readonly LoggerInterface $logger,
        private readonly Mailer          $mailer,
    ) {}

    public function process(array $items): void {
        $this->db->prepare("INSERT INTO orders ...")->execute([]);
        $this->logger->info("Order created");
        $this->mailer->send("...");
    }
}
```

The rule: static is appropriate for pure functions (no side effects, no dependencies), factory methods, and constants. Avoid static for anything that touches a database, filesystem, or network — those are dependencies that should be injected, not hardcoded.

## Putting it together — a complete model class

```php
<?php
declare(strict_types=1);

trait Timestampable {
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    protected function initTimestamps(): void {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

trait SoftDeletable {
    private ?\DateTimeImmutable $deletedAt = null;

    public function softDelete(): void { $this->deletedAt = new \DateTimeImmutable(); }
    public function restore(): void    { $this->deletedAt = null; }
    public function isDeleted(): bool  { return $this->deletedAt !== null; }
}

trait Serializable {
    abstract public function toArray(): array;

    public function toJson(int $flags = 0): string {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $flags);
    }

    public function toPrettyJson(): string {
        return $this->toJson(JSON_PRETTY_PRINT);
    }
}

class Post {
    use Timestampable, SoftDeletable, Serializable;

    private string $slug;

    public function __construct(
        public readonly int    $id,
        public string          $title,
        public string          $body,
        private string         $status = "draft",
    ) {
        $this->initTimestamps();
        $this->slug = $this->generateSlug($title);
    }

    private function generateSlug(string $title): string {
        $s = mb_strtolower(trim($title));
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
        $s = preg_replace('/\s+/', '-', $s);
        return trim($s, '-');
    }

    public function publish(): void {
        $this->status = "published";
        $this->touch();
    }

    public function getSlug(): string  { return $this->slug; }
    public function getStatus(): string { return $this->status; }

    // Required by Serializable trait
    public function toArray(): array {
        return [
            "id"         => $this->id,
            "title"      => $this->title,
            "slug"       => $this->slug,
            "body"       => $this->body,
            "status"     => $this->status,
            "deleted"    => $this->isDeleted(),
            "created_at" => $this->getCreatedAt()->format("Y-m-d H:i:s"),
            "updated_at" => $this->getUpdatedAt()->format("Y-m-d H:i:s"),
        ];
    }
}

$post = new Post(1, "PHP Traits in Practice", "Today we learn traits...");
$post->publish();
echo $post->toPrettyJson() . "\n";
$post->softDelete();
var_dump($post->isDeleted());   // true
```

---

## Today's exercise

![[Pasted image 20260603095943.png]]

The stretch goal's `FleetMonitor` is the observer pattern — used in React's state system, Laravel's event dispatcher, Node's EventEmitter, and the MQTT subscription model you already know from your embedded work. You've been working with observers in C via libmosquitto callbacks since the beginning of your daemon project; this is the same concept in OOP form.

With Day 20 done you have the complete OOP toolkit: classes, inheritance, interfaces, and traits. Day 21 is Composer and autoloading — the infrastructure that makes all of this scale beyond single files.