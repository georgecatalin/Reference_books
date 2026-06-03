

You've built a substantial application. Today you learn to verify it doesn't break when you change it. Tests are not optional in professional work — they're what let you refactor confidently, onboard teammates safely, and ship features without manually clicking through every flow after every change.

## The mental model — what tests actually do

```
Without tests:                   With tests:
──────────────────────────────────────────────────────
Change code → manually verify    Change code → run tests → pass/fail in seconds
Fear of refactoring              Refactor freely — tests catch regressions
"Works on my machine"            Reproducible — same result everywhere
Bugs found in production         Bugs found before commit
```

Tests are executable documentation. A test named `test_machine_cannot_dispense_more_than_filled` tells you exactly what the code promises. The test suite is a living specification of your system's behaviour.

## Installing PHPUnit

```bash
composer require --dev phpunit/phpunit

# Verify
./vendor/bin/phpunit --version
```

Create `phpunit.xml` in your project root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         stopOnFailure="false">

    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>

</phpunit>
```

Folder structure:

```
tests/
  Unit/
    Models/
      MachineTest.php
      PostTest.php
    Services/
      FleetServiceTest.php
    Http/
      Clients/
        WeatherClientTest.php
  Integration/
    Repositories/
      MachineRepositoryTest.php
```

## The three types of tests

```
Unit tests:        Test one class in isolation — fast, no DB, no network
Integration tests: Test classes working together — hits real DB or filesystem
Feature/E2E tests: Test the full HTTP flow — browser or curl against running app

Ratio target: 70% unit, 20% integration, 10% feature
Run time:      unit < 1s, integration < 30s, feature < 5min
```

Start with unit tests. They're fast, give instant feedback, and force you to write testable code — which is almost always better-designed code.

## Your first test — a Machine unit test

```php
<?php
// tests/Unit/Models/MachineTest.php
declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Machine;
use PHPUnit\Framework\TestCase;

class MachineTest extends TestCase {
    // Every test method starts with test_ or has @test annotation
    // PHPUnit finds them automatically

    private function makeMachine(
        string $id       = "vend-001",
        string $location = "Floor 1",
        string $status   = Machine::STATUS_ONLINE,
        int    $slots    = 20,
        int    $filled   = 10,
    ): Machine {
        return new Machine($id, $location, $status, $slots, $filled);
    }

    // ─── fillPercent ───────────────────────────────────────────────

    public function test_fill_percent_returns_correct_value(): void {
        $machine = $this->makeMachine(slots: 20, filled: 17);
        $this->assertEqualsWithDelta(85.0, $machine->fillPercent(), 0.01);
    }

    public function test_fill_percent_returns_zero_when_empty(): void {
        $machine = $this->makeMachine(slots: 20, filled: 0);
        $this->assertSame(0.0, $machine->fillPercent());
    }

    public function test_fill_percent_returns_zero_when_slots_is_zero(): void {
        $machine = $this->makeMachine(slots: 0, filled: 0);
        $this->assertSame(0.0, $machine->fillPercent());
    }

    public function test_fill_percent_returns_100_when_full(): void {
        $machine = $this->makeMachine(slots: 10, filled: 10);
        $this->assertSame(100.0, $machine->fillPercent());
    }

    // ─── setStatus ────────────────────────────────────────────────

    public function test_set_status_online_succeeds(): void {
        $machine = $this->makeMachine(status: Machine::STATUS_OFFLINE);
        $machine->setStatus(Machine::STATUS_ONLINE);
        $this->assertSame(Machine::STATUS_ONLINE, $machine->getStatus());
    }

    public function test_set_status_throws_on_invalid_status(): void {
        $machine = $this->makeMachine();

        $this->expectException(\InvalidArgumentException::class);
        $machine->setStatus("destroyed");
    }

    public function test_set_status_throws_with_descriptive_message(): void {
        $machine = $this->makeMachine();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("destroyed");  // message contains the bad value

        $machine->setStatus("destroyed");
    }

    // ─── dispense ─────────────────────────────────────────────────

    public function test_dispense_reduces_filled_count(): void {
        $machine = $this->makeMachine(slots: 20, filled: 10);
        $machine->dispense(3);
        $this->assertSame(7, $machine->getStock());
    }

    public function test_dispense_throws_when_quantity_exceeds_stock(): void {
        $machine = $this->makeMachine(slots: 20, filled: 5);

        $this->expectException(\RuntimeException::class);
        $machine->dispense(10);
    }

    public function test_dispense_throws_on_zero_quantity(): void {
        $machine = $this->makeMachine();

        $this->expectException(\InvalidArgumentException::class);
        $machine->dispense(0);
    }

    public function test_dispense_throws_on_negative_quantity(): void {
        $machine = $this->makeMachine();

        $this->expectException(\InvalidArgumentException::class);
        $machine->dispense(-1);
    }

    public function test_dispense_allows_emptying_to_zero(): void {
        $machine = $this->makeMachine(slots: 20, filled: 5);
        $machine->dispense(5);
        $this->assertSame(0, $machine->getStock());
    }

    // ─── restock ──────────────────────────────────────────────────

    public function test_restock_increases_filled_count(): void {
        $machine = $this->makeMachine(slots: 20, filled: 5);
        $machine->restock(10);
        $this->assertSame(15, $machine->getStock());
    }

    public function test_restock_does_not_exceed_slots(): void {
        $machine = $this->makeMachine(slots: 20, filled: 18);
        $machine->restock(10);   // would be 28, but capped at 20
        $this->assertSame(20, $machine->getStock());
    }

    public function test_restock_throws_on_zero_quantity(): void {
        $machine = $this->makeMachine();

        $this->expectException(\InvalidArgumentException::class);
        $machine->restock(0);
    }

    // ─── needsRestock ─────────────────────────────────────────────

    public function test_needs_restock_when_below_threshold(): void {
        $machine = $this->makeMachine(slots: 20, filled: 5);  // 25%
        $this->assertTrue($machine->needsRestock(30));
    }

    public function test_does_not_need_restock_when_above_threshold(): void {
        $machine = $this->makeMachine(slots: 20, filled: 15);  // 75%
        $this->assertFalse($machine->needsRestock(30));
    }

    public function test_needs_restock_uses_30_percent_default_threshold(): void {
        $below = $this->makeMachine(slots: 20, filled: 5);   // 25% — needs restock
        $above = $this->makeMachine(slots: 20, filled: 10);  // 50% — fine

        $this->assertTrue($below->needsRestock());
        $this->assertFalse($above->needsRestock());
    }

    // ─── isOnline ─────────────────────────────────────────────────

    public function test_is_online_returns_true_for_online_status(): void {
        $machine = $this->makeMachine(status: Machine::STATUS_ONLINE);
        $this->assertTrue($machine->isOnline());
    }

    public function test_is_online_returns_false_for_offline_status(): void {
        $machine = $this->makeMachine(status: Machine::STATUS_OFFLINE);
        $this->assertFalse($machine->isOnline());
    }

    public function test_is_online_returns_false_for_fault_status(): void {
        $machine = $this->makeMachine(status: Machine::STATUS_FAULT);
        $this->assertFalse($machine->isOnline());
    }

    // ─── toArray ──────────────────────────────────────────────────

    public function test_to_array_contains_expected_keys(): void {
        $machine = $this->makeMachine();
        $array   = $machine->toArray();

        $this->assertArrayHasKey("id",       $array);
        $this->assertArrayHasKey("location", $array);
        $this->assertArrayHasKey("status",   $array);
        $this->assertArrayHasKey("fill_pct", $array);
    }

    public function test_to_array_id_matches(): void {
        $machine = $this->makeMachine(id: "vend-042");
        $this->assertSame("vend-042", $machine->toArray()["id"]);
    }
}
```

Run it:

```bash
./vendor/bin/phpunit tests/Unit/Models/MachineTest.php --testdox
```

`--testdox` formats test names as readable sentences — great for documentation.

## Data providers — testing many inputs efficiently

```php
<?php
// tests/Unit/Models/MachineTest.php — add to the class

public function test_fill_percent_with_various_inputs(): void {
    // Inline data — fine for a few cases
    $cases = [
        [20, 20, 100.0],
        [20, 10, 50.0],
        [20, 0,  0.0],
        [10, 3,  30.0],
        [15, 14, 93.3],
    ];

    foreach ($cases as [$slots, $filled, $expected]) {
        $machine = $this->makeMachine(slots: $slots, filled: $filled);
        $this->assertEqualsWithDelta(
            $expected,
            $machine->fillPercent(),
            0.1,
            "Failed for slots=$slots filled=$filled"
        );
    }
}

// Better — data provider: PHPUnit shows each case separately in output
/**
 * @dataProvider invalidStatusProvider
 */
public function test_set_status_rejects_invalid_values(string $badStatus): void {
    $machine = $this->makeMachine();
    $this->expectException(\InvalidArgumentException::class);
    $machine->setStatus($badStatus);
}

public static function invalidStatusProvider(): array {
    return [
        "empty string"   => [""],
        "uppercase"      => ["ONLINE"],
        "typo"           => ["onlne"],
        "sql injection"  => ["online'; DROP TABLE machines; --"],
        "random string"  => ["destroyed"],
        "numeric"        => ["1"],
    ];
}
```

Data providers run the same test method once per dataset. Each dataset is shown separately in `--testdox` output, making it easy to see which case failed.

## Mocking — testing in isolation

Unit tests must not touch the database, filesystem, or network. Use mocks to replace real dependencies with controlled fakes:

```php
<?php
// tests/Unit/Services/FleetServiceTest.php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Machine;
use App\Repositories\MachineRepository;
use App\Services\FleetService;
use App\Exceptions\DeviceNotFoundException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class FleetServiceTest extends TestCase {
    private MachineRepository&MockObject $repo;
    private FleetService                 $service;

    protected function setUp(): void {
        // Create a mock of MachineRepository
        // PHPUnit generates a class that implements the same interface
        // but all methods return null by default until you configure them
        $this->repo    = $this->createMock(MachineRepository::class);
        $this->service = new FleetService($this->repo);
    }

    public function test_get_all_returns_machines_from_repository(): void {
        $machines = [
            new Machine("vend-001", "Floor 1", Machine::STATUS_ONLINE),
            new Machine("vend-002", "Floor 2", Machine::STATUS_OFFLINE),
        ];

        // Configure: when findAll() is called, return these machines
        $this->repo
            ->expects($this->once())   // assert it's called exactly once
            ->method("findAll")
            ->willReturn($machines);

        $result = $this->service->getAll();

        $this->assertCount(2, $result);
        $this->assertSame("vend-001", $result[0]->id);
    }

    public function test_get_by_id_returns_correct_machine(): void {
        $machine = new Machine("vend-001", "Floor 1", Machine::STATUS_ONLINE);

        $this->repo
            ->expects($this->once())
            ->method("findById")
            ->with("vend-001")   // assert it's called with this argument
            ->willReturn($machine);

        $result = $this->service->getById("vend-001");

        $this->assertSame("vend-001", $result->id);
    }

    public function test_get_by_id_propagates_not_found_exception(): void {
        $this->repo
            ->method("findById")
            ->willThrowException(new DeviceNotFoundException("vend-999"));

        $this->expectException(DeviceNotFoundException::class);
        $this->service->getById("vend-999");
    }

    public function test_get_machines_needing_restock_filters_correctly(): void {
        $machines = [
            new Machine("vend-001", "Floor 1", Machine::STATUS_ONLINE, 20, 17),  // 85% — fine
            new Machine("vend-002", "Floor 2", Machine::STATUS_ONLINE, 20, 5),   // 25% — restock
            new Machine("vend-003", "Lobby",   Machine::STATUS_FAULT,  20, 2),   // 10% — restock
        ];

        $this->repo->method("findAll")->willReturn($machines);

        $needRestock = $this->service->getMachinesNeedingRestock(30);

        $this->assertCount(2, $needRestock);
    }

    public function test_bring_online_saves_updated_machine(): void {
        $machine = new Machine("vend-001", "Floor 1", Machine::STATUS_OFFLINE);

        $this->repo->method("findById")->willReturn($machine);

        // Assert save() is called exactly once with a machine that is online
        $this->repo
            ->expects($this->once())
            ->method("save")
            ->with($this->callback(function(Machine $m): bool {
                return $m->getStatus() === Machine::STATUS_ONLINE;
            }));

        $this->service->bringOnline("vend-001");
    }

    public function test_bring_online_returns_the_updated_machine(): void {
        $machine = new Machine("vend-001", "Floor 1", Machine::STATUS_OFFLINE);
        $this->repo->method("findById")->willReturn($machine);
        $this->repo->method("save")->willReturn(null);

        $result = $this->service->bringOnline("vend-001");

        $this->assertSame(Machine::STATUS_ONLINE, $result->getStatus());
    }
}
```

The `setUp()` method runs before every test. The mock is recreated fresh for each test — no shared state between tests, which is critical for test isolation.

## Testing exceptions — full coverage

```php
<?php
// tests/Unit/Models/TemperatureTest.php
declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Temperature;
use PHPUnit\Framework\TestCase;

class TemperatureTest extends TestCase {
    public function test_celsius_factory_creates_correct_value(): void {
        $t = Temperature::celsius(100.0);
        $this->assertEqualsWithDelta(100.0, $t->getValue(), 0.001);
    }

    public function test_conversion_from_fahrenheit_to_celsius(): void {
        $t = Temperature::fahrenheit(212.0);
        $this->assertEqualsWithDelta(100.0, $t->toCelsius()->getValue(), 0.01);
    }

    public function test_conversion_from_kelvin_to_celsius(): void {
        $t = Temperature::kelvin(273.15);
        $this->assertEqualsWithDelta(0.0, $t->toCelsius()->getValue(), 0.01);
    }

    public function test_kelvin_throws_on_negative_value(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("negative");  // message contains this word
        Temperature::kelvin(-1.0);
    }

    public function test_from_string_parses_celsius(): void {
        $t = Temperature::fromString("37C");
        $this->assertEqualsWithDelta(37.0, $t->getValue(), 0.001);
    }

    /**
     * @dataProvider invalidStringProvider
     */
    public function test_from_string_throws_on_invalid_input(string $input): void {
        $this->expectException(\InvalidArgumentException::class);
        Temperature::fromString($input);
    }

    public static function invalidStringProvider(): array {
        return [
            "empty"         => [""],
            "no unit"       => ["37"],
            "bad unit"      => ["37X"],
            "non-numeric"   => ["hotC"],
            "negative K"    => ["-10K"],
        ];
    }
}
```

## Integration test — hitting the real database

```php
<?php
// tests/Integration/Repositories/MachineRepositoryTest.php
declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Models\Machine;
use App\Repositories\MachineRepository;
use App\Exceptions\DeviceNotFoundException;
use PHPUnit\Framework\TestCase;
use PDO;

class MachineRepositoryTest extends TestCase {
    private PDO               $pdo;
    private MachineRepository $repo;

    protected function setUp(): void {
        // Use a separate test database — never the production DB
        $this->pdo = new PDO(
            "mysql:host=127.0.0.1;dbname=fleet_test;charset=utf8mb4",
            "fleet_user",
            "strongpassword",
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        // Wrap each test in a transaction — rolls back after test
        $this->pdo->beginTransaction();

        $this->repo = new MachineRepository($this->pdo);
    }

    protected function tearDown(): void {
        // Roll back — database is pristine for next test
        $this->pdo->rollBack();
    }

    public function test_save_and_find_by_id(): void {
        $machine = new Machine("test-001", "Test Floor", Machine::STATUS_ONLINE, 10, 5);
        $this->repo->save($machine);

        $found = $this->repo->findById("test-001");

        $this->assertSame("test-001",            $found->id);
        $this->assertSame("Test Floor",          $found->location);
        $this->assertSame(Machine::STATUS_ONLINE, $found->getStatus());
    }

    public function test_find_by_id_throws_when_not_found(): void {
        $this->expectException(DeviceNotFoundException::class);
        $this->repo->findById("nonexistent-999");
    }

    public function test_find_all_returns_saved_machines(): void {
        $this->repo->save(new Machine("test-a", "Location A", Machine::STATUS_ONLINE));
        $this->repo->save(new Machine("test-b", "Location B", Machine::STATUS_OFFLINE));

        $all = $this->repo->findAll();

        $ids = array_map(fn($m) => $m->id, $all);
        $this->assertContains("test-a", $ids);
        $this->assertContains("test-b", $ids);
    }

    public function test_delete_removes_machine(): void {
        $this->repo->save(new Machine("test-del", "Floor X", Machine::STATUS_OFFLINE));
        $this->repo->delete("test-del");

        $this->expectException(DeviceNotFoundException::class);
        $this->repo->findById("test-del");
    }

    public function test_delete_throws_when_machine_not_found(): void {
        $this->expectException(DeviceNotFoundException::class);
        $this->repo->delete("does-not-exist");
    }
}
```

The transaction trick: `beginTransaction()` in `setUp()`, `rollBack()` in `tearDown()`. Every test runs inside a transaction that's never committed — the database is always clean for the next test. Fast and reliable.

## Testing an API client with mocked HTTP

```php
<?php
// tests/Unit/Http/Clients/WeatherClientTest.php
declare(strict_types=1);

namespace Tests\Unit\Http\Clients;

use App\Http\Clients\WeatherClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class WeatherClientTest extends TestCase {
    private function makeClient(array $responses): WeatherClient {
        // MockHandler queues responses to return in order
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $guzzle  = new Client(["handler" => $stack]);

        // Inject the mocked Guzzle client
        // WeatherClient needs to accept an optional Client parameter
        return new WeatherClient(httpClient: $guzzle);
    }

    public function test_current_returns_weather_data(): void {
        $responseBody = json_encode([
            "current_weather" => [
                "temperature" => 22.5,
                "windspeed"   => 14.0,
                "weathercode" => 0,
            ],
        ]);

        $client = $this->makeClient([
            new Response(200, ["Content-Type" => "application/json"], $responseBody),
        ]);

        $weather = $client->current(45.47, 28.05);

        $this->assertSame(22.5, $weather["temperature"]);
        $this->assertSame(14.0, $weather["windspeed"]);
    }

    public function test_current_throws_on_network_error(): void {
        $client = $this->makeClient([
            new ConnectException("Connection refused", new Request("GET", "/")),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unreachable");

        $client->current(45.47, 28.05);
    }

    public function test_current_throws_on_server_error(): void {
        $client = $this->makeClient([
            new Response(500, [], "Internal Server Error"),
        ]);

        $this->expectException(\RuntimeException::class);
        $client->current(45.47, 28.05);
    }

    public function test_current_throws_on_invalid_json(): void {
        $client = $this->makeClient([
            new Response(200, ["Content-Type" => "application/json"], "not json {{{"),
        ]);

        $this->expectException(\RuntimeException::class);
        $client->current(45.47, 28.05);
    }
}
```

`MockHandler` lets you test HTTP clients without making real network requests. Queue up responses and Guzzle returns them in order. Test the happy path, network errors, server errors, and malformed responses — all without a live API.

## Running tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific suite
./vendor/bin/phpunit --testsuite Unit

# Run specific file
./vendor/bin/phpunit tests/Unit/Models/MachineTest.php

# Run specific test method
./vendor/bin/phpunit --filter test_dispense_reduces_filled_count

# Human-readable output
./vendor/bin/phpunit --testdox

# With code coverage (requires Xdebug or PCOV)
./vendor/bin/phpunit --coverage-html coverage/
```

Add a `composer.json` script for convenience:

```json
{
    "scripts": {
        "test":       "./vendor/bin/phpunit",
        "test:unit":  "./vendor/bin/phpunit --testsuite Unit",
        "test:watch": "find src tests -name '*.php' | entr composer test"
    }
}
```

Now `composer test` runs your suite.

## PHPUnit assertions — the most useful ones

```php
<?php
// Equality
$this->assertSame($expected, $actual);         // strict === comparison
$this->assertEquals($expected, $actual);       // loose == comparison
$this->assertNotSame($a, $b);
$this->assertEqualsWithDelta(3.14, $val, 0.01); // float comparison with tolerance

// Type
$this->assertInstanceOf(Machine::class, $obj);
$this->assertIsArray($val);
$this->assertIsString($val);
$this->assertIsInt($val);
$this->assertIsFloat($val);
$this->assertNull($val);
$this->assertNotNull($val);

// Boolean
$this->assertTrue($val);
$this->assertFalse($val);

// Arrays
$this->assertCount(3, $array);
$this->assertEmpty($array);
$this->assertNotEmpty($array);
$this->assertArrayHasKey("id", $array);
$this->assertContains("vend-001", $array);

// Strings
$this->assertStringContainsString("floor", $string);
$this->assertStringStartsWith("vend-", $id);
$this->assertMatchesRegularExpression('/^\d+$/', $id);

// Exceptions
$this->expectException(\InvalidArgumentException::class);
$this->expectExceptionMessage("cannot be negative");
$this->expectExceptionCode(404);
```

Use `assertSame` by default. Use `assertEquals` only when you explicitly want loose comparison. This mirrors the `===` vs `==` rule from Day 2.

---

## Today's exercise

![[Pasted image 20260603101415.png]]

**Phase 3 is complete after today.** You now have OOP, interfaces, traits, Composer, MVC, REST APIs, external API consumption, and automated tests — the full professional PHP toolkit.

The most important habit from today: write tests as you build, not after. The moment you finish a new method, write the test for it. The test takes 5 minutes when the code is fresh. It takes 30 minutes a week later when you've forgotten what edge cases you considered. And it saves hours when a change breaks something you weren't expecting.

Phase 4 starts on Day 26 — performance, caching with Redis, and background jobs. The foundation you've built in Phase 3 is exactly what makes those topics approachable. Paste your test suite when ready.