
You've been using `require_once` to load files manually. That works for small projects. The moment you have 20+ classes across nested directories it becomes unmanageable — every file needs a list of includes at the top, the order matters, and a missing require causes a fatal error at runtime. Composer solves this permanently.

## What Composer actually is

Composer does two separate things that people often conflate:

```
Thing 1: Package manager
  — downloads third-party libraries from packagist.org
  — manages versions and dependencies between packages
  — like apt for PHP libraries

Thing 2: Autoloader
  — maps class names to file paths using a standard (PSR-4)
  — when PHP encounters an unknown class, the autoloader finds and loads the file
  — you never write require_once for your own classes again
```

Today you'll use both. The autoloader is the more important one for day-to-day development.

## Step 1 — Install Composer

```bash
# Download and install globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify
composer --version
```

## Step 2 — Initialise a project

```bash
mkdir fleet-manager && cd fleet-manager
composer init
```

Answer the prompts or just hit enter for defaults. This creates `composer.json`:

```json
{
    "name": "george/fleet-manager",
    "description": "MQTT fleet management system",
    "type": "project",
    "require": {},
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    }
}
```

The critical part is `autoload.psr-4`. This tells Composer: any class whose name starts with `App\` lives in the `src/` directory.

## PSR-4 — the namespace-to-path mapping

PSR-4 is a standard that maps PHP namespaces to directory paths. The rule is simple:

```
Namespace prefix  →  Base directory
App\              →  src/

App\Models\Machine          →  src/Models/Machine.php
App\Services\FleetService   →  src/Services/FleetService.php
App\Http\Controllers\MachineController  →  src/Http/Controllers/MachineController.php
```

The class name must match the filename exactly, including capitalisation. The namespace must match the directory path exactly. Break either rule and the autoloader can't find the class.

## Step 3 — Folder structure

```
fleet-manager/
  composer.json
  composer.lock        ← generated, commit to version control
  vendor/              ← generated, NEVER commit to version control
    autoload.php       ← the file you include
  src/
    Models/
      Machine.php
      Post.php
      User.php
    Services/
      FleetService.php
      NotificationService.php
    Repositories/
      MachineRepository.php
    Exceptions/
      FleetException.php
      DeviceNotFoundException.php
    Http/
      Controllers/
        MachineController.php
  public/
    index.php
  tests/
    Models/
      MachineTest.php
```

## Step 4 — Writing namespaced classes

Every class file needs exactly two things at the top: a `declare(strict_types=1)` and a `namespace` declaration that matches its directory path:

```php
<?php
// src/Models/Machine.php
declare(strict_types=1);

namespace App\Models;

class Machine {
    public const STATUS_ONLINE  = "online";
    public const STATUS_OFFLINE = "offline";
    public const STATUS_FAULT   = "fault";

    public function __construct(
        public readonly string $id,
        public string          $location,
        private string         $status = self::STATUS_OFFLINE,
        private int            $slots  = 20,
        private int            $filled = 0,
    ) {}

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): void {
        if (!in_array($status, [self::STATUS_ONLINE, self::STATUS_OFFLINE, self::STATUS_FAULT], true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }
        $this->status = $status;
    }

    public function fillPercent(): float {
        return $this->slots > 0
            ? round($this->filled / $this->slots * 100, 1)
            : 0.0;
    }

    public function restock(int $qty): void {
        if ($qty <= 0) throw new \InvalidArgumentException("Qty must be positive.");
        $this->filled = min($this->slots, $this->filled + $qty);
    }

    public function dispense(int $qty = 1): void {
        if ($qty > $this->filled) {
            throw new \RuntimeException("Insufficient stock: have {$this->filled}, requested $qty.");
        }
        $this->filled -= $qty;
    }

    public function toArray(): array {
        return [
            "id"          => $this->id,
            "location"    => $this->location,
            "status"      => $this->status,
            "fill_pct"    => $this->fillPercent(),
        ];
    }

    public function __toString(): string {
        return "{$this->id} ({$this->location}) — {$this->status} — {$this->fillPercent()}% full";
    }
}
```

```php
<?php
// src/Exceptions/DeviceNotFoundException.php
declare(strict_types=1);

namespace App\Exceptions;

class DeviceNotFoundException extends \RuntimeException {
    public function __construct(
        private readonly string $deviceId,
        string $message = "",
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message ?: "Device not found: $deviceId",
            0,
            $previous
        );
    }

    public function getDeviceId(): string {
        return $this->deviceId;
    }
}
```

```php
<?php
// src/Repositories/MachineRepository.php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Machine;
use App\Exceptions\DeviceNotFoundException;
use PDO;

class MachineRepository {
    public function __construct(private readonly PDO $pdo) {}

    public function findById(string $id): Machine {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM machines WHERE id = :id"
        );
        $stmt->execute([":id" => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new DeviceNotFoundException($id);
        }

        return $this->hydrate($row);
    }

    public function findAll(string $status = null): array {
        if ($status !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM machines WHERE status = :status ORDER BY id"
            );
            $stmt->execute([":status" => $status]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM machines ORDER BY id");
        }

        return array_map([$this, "hydrate"], $stmt->fetchAll());
    }

    public function save(Machine $machine): void {
        $this->pdo->prepare("
            INSERT INTO machines (id, location, status)
            VALUES (:id, :location, :status)
            ON DUPLICATE KEY UPDATE
                location = VALUES(location),
                status   = VALUES(status)
        ")->execute([
            ":id"       => $machine->id,
            ":location" => $machine->location,
            ":status"   => $machine->getStatus(),
        ]);
    }

    public function delete(string $id): void {
        $affected = $this->pdo->prepare(
            "DELETE FROM machines WHERE id = :id"
        );
        $affected->execute([":id" => $id]);

        if ($affected->rowCount() === 0) {
            throw new DeviceNotFoundException($id);
        }
    }

    private function hydrate(array $row): Machine {
        return new Machine(
            id:       $row["id"],
            location: $row["location"],
            status:   $row["status"],
            slots:    (int)$row["slots"],
            filled:   (int)($row["filled"] ?? 0),
        );
    }
}
```

```php
<?php
// src/Services/FleetService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\Machine;
use App\Repositories\MachineRepository;
use App\Exceptions\DeviceNotFoundException;

class FleetService {
    public function __construct(
        private readonly MachineRepository $machines,
    ) {}

    public function getOnlineMachines(): array {
        return $this->machines->findAll(Machine::STATUS_ONLINE);
    }

    public function getMachinesNeedingRestock(int $threshold = 30): array {
        return array_filter(
            $this->machines->findAll(),
            fn(Machine $m): bool => $m->fillPercent() < $threshold
        );
    }

    public function bringOnline(string $id): Machine {
        $machine = $this->machines->findById($id);
        $machine->setStatus(Machine::STATUS_ONLINE);
        $this->machines->save($machine);
        return $machine;
    }

    public function reportFault(string $id): Machine {
        $machine = $this->machines->findById($id);
        $machine->setStatus(Machine::STATUS_FAULT);
        $this->machines->save($machine);
        return $machine;
    }
}
```

## Step 5 — Using the autoloader

```php
<?php
// public/index.php
declare(strict_types=1);

// One line — loads every class in your project automatically
require_once __DIR__ . "/../vendor/autoload.php";

// Now use any class directly — no require needed
use App\Models\Machine;
use App\Services\FleetService;
use App\Repositories\MachineRepository;
use App\Exceptions\DeviceNotFoundException;

$pdo  = new PDO("mysql:host=127.0.0.1;dbname=fleet_db;charset=utf8mb4",
                "fleet_user", "strongpassword", [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

$repo    = new MachineRepository($pdo);
$service = new FleetService($repo);

try {
    $machine = $service->bringOnline("vend-001");
    echo $machine . "\n";
} catch (DeviceNotFoundException $e) {
    echo "Not found: " . $e->getDeviceId() . "\n";
}

$needRestock = $service->getMachinesNeedingRestock();
foreach ($needRestock as $m) {
    echo "Restock needed: $m\n";
}
```

After adding or changing classes, run:

```bash
composer dump-autoload
```

This regenerates the autoloader map. You need to run it when you add new classes or change namespaces. During development you can add `"optimize": false` to the autoload config — in production run `composer dump-autoload --optimize` to generate a classmap (faster than PSR-4 scanning).

## Step 6 — Installing third-party packages

```bash
# Install a package
composer require vlucas/phpdotenv

# Install a dev-only package
composer require --dev phpunit/phpunit

# See what's installed
composer show

# Update all packages
composer update

# Install from existing composer.json (on a new machine or after git clone)
composer install
```

After `composer require`, the package appears in `vendor/` and is automatically available through the autoloader — no additional setup.

## Using vlucas/phpdotenv — environment variables

Never hardcode credentials. Store them in a `.env` file and load with phpdotenv:

```bash
composer require vlucas/phpdotenv
```

```ini
# .env — never commit this file
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fleet_db
DB_USER=fleet_user
DB_PASS=strongpassword

APP_ENV=development
APP_SECRET=your-random-32-char-secret-here

MQTT_BROKER=mqtt.factory.local
MQTT_PORT=1883
```

```php
<?php
// public/index.php
declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Require specific variables — throws if missing
$dotenv->required(["DB_HOST", "DB_NAME", "DB_USER", "DB_PASS", "APP_SECRET"]);

// Access via $_ENV or getenv()
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $_ENV["DB_HOST"],
        $_ENV["DB_PORT"] ?? "3306",
        $_ENV["DB_NAME"]
    ),
    $_ENV["DB_USER"],
    $_ENV["DB_PASS"],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
```

Add `.env` to `.gitignore` immediately:

```bash
echo ".env" >> .gitignore
echo "vendor/" >> .gitignore
```

Commit `.env.example` with placeholder values so other developers know what variables to set:

```ini
# .env.example — commit this
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fleet_db
DB_USER=
DB_PASS=

APP_ENV=development
APP_SECRET=
```

## Autoloading files — helpers and functions

PSR-4 only autoloads classes. For plain function files (your `utils.php`, `auth.php`, etc.) use the `files` autoload key:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        },
        "files": [
            "src/helpers.php",
            "src/auth.php"
        ]
    }
}
```

These files are included on every request automatically — no `require_once` needed.

## composer.lock — the file you always commit

`composer.lock` records the exact version of every package installed. When a teammate runs `composer install`, they get identical versions — not "compatible" versions, identical ones. This eliminates "works on my machine" problems caused by version drift.

```
composer.json  →  "I need phpunit ^11.0" (flexible)
composer.lock  →  "Use phpunit 11.2.3 exactly" (locked)

composer install   →  uses lock file (teammates, CI, production)
composer update    →  resolves fresh versions, updates lock file (you, intentionally)
```

Never run `composer update` on production. Run it locally, test, commit the updated `composer.lock`, then deploy.

---

## Today's exercise

![[Pasted image 20260603100227.png]]
Part A is the most important migration you'll do in this course. Going from `require_once` spaghetti to a properly namespaced PSR-4 structure is the moment your project starts looking like professional PHP. The stretch goal's repository pattern is what makes Day 23's REST API clean — handlers call services, services call repositories, repositories talk to databases. Each layer only knows about the one below it.

One practical note: after `composer dump-autoload`, if a class still isn't found, the three things to check in order are: namespace exactly matches directory path (case-sensitive on Linux), filename exactly matches class name, and `composer dump-autoload` was run after the last change. Those three cover 99% of autoload issues.

Day 22 is MVC — you'll build a proper model-view-controller structure from scratch using everything from Days 18–21. Paste your code when ready.