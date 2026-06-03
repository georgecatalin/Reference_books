

Some tasks are too slow to do during an HTTP request. Sending an email, generating a PDF report, processing an image, initiating an OTA firmware update, establishing an SSH tunnel — all of these can take seconds or fail and need retrying. Doing them synchronously means the user waits and stares at a spinner. Queues move that work to a background process.

## The mental model

```
Without queues:                     With queues:
────────────────────────────────────────────────────────────
Request → process email → respond   Request → queue job → respond immediately
User waits 3 seconds                User gets response in 50ms
Email server down = user gets error Job retried automatically when server recovers
Spike in traffic = timeout cascade  Jobs processed at controlled rate
```

```
Producer (your web app)             Consumer (worker process)
─────────────────────               ──────────────────────────
HTTP request comes in               Runs continuously in background
Create job, push to queue  ──────▶  Pop job from queue
Respond to user                     Execute job
                                    ✓ Success → delete from queue
                                    ✗ Failure → retry or dead-letter
```

A queue is just a list. The web process pushes to one end. The worker pops from the other. Redis is the perfect queue backend — you already have it installed.

## Part 1 — The job contract

```php
<?php
// src/Queue/Job.php
declare(strict_types=1);

namespace App\Queue;

interface Job {
    // Every job must be executable
    public function handle(): void;

    // Serialise to array for storage
    public function toArray(): array;

    // Human-readable name for logging
    public function getName(): string;

    // How many times to retry on failure
    public function maxAttempts(): int;

    // Seconds to wait before retrying
    public function retryDelay(): int;
}
```

```php
<?php
// src/Queue/AbstractJob.php
declare(strict_types=1);

namespace App\Queue;

abstract class AbstractJob implements Job {
    public function maxAttempts(): int { return 3; }
    public function retryDelay(): int  { return 5; }
    public function getName(): string  { return static::class; }
}
```

## Part 2 — Concrete job classes

```php
<?php
// src/Jobs/SendEmailJob.php
declare(strict_types=1);

namespace App\Jobs;

use App\Queue\AbstractJob;

class SendEmailJob extends AbstractJob {
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $body,
        private readonly string $fromName = "Fleet Manager",
    ) {}

    public function handle(): void {
        // In production: use a real mailer (PHPMailer, Symfony Mailer)
        // For now: simulate with a log entry
        $logLine = sprintf(
            "[%s] EMAIL to=%s subject=%s\n",
            date("Y-m-d H:i:s"),
            $this->to,
            $this->subject
        );

        file_put_contents(
            __DIR__ . "/../../storage/email.log",
            $logLine,
            FILE_APPEND | LOCK_EX
        );

        // Simulate occasional failure for testing retry logic
        if (random_int(1, 10) === 1) {
            throw new \RuntimeException("SMTP server temporarily unavailable.");
        }

        echo "  Sent email to {$this->to}: {$this->subject}\n";
    }

    public function toArray(): array {
        return [
            "to"        => $this->to,
            "subject"   => $this->subject,
            "body"      => $this->body,
            "from_name" => $this->fromName,
        ];
    }

    public function getName(): string { return "send_email"; }
}
```

```php
<?php
// src/Jobs/OtaUpdateJob.php
declare(strict_types=1);

namespace App\Jobs;

use App\Queue\AbstractJob;
use App\Http\Clients\MqttBrokerClient;

class OtaUpdateJob extends AbstractJob {
    public function __construct(
        private readonly string $machineId,
        private readonly string $firmwareVersion,
        private readonly string $firmwareUrl,
        private readonly string $sha256,
    ) {}

    public function handle(): void {
        echo "  Initiating OTA for {$this->machineId} → v{$this->firmwareVersion}\n";

        // Publish OTA command to the device via MQTT broker HTTP API
        // This is exactly what your C daemon subscribes to
        $topic   = "deviceCluster/{$this->machineId}/ota/update";
        $payload = json_encode([
            "job_id"       => "ota-" . bin2hex(random_bytes(4)),
            "version"      => $this->firmwareVersion,
            "http_url"     => $this->firmwareUrl,
            "sha256"       => $this->sha256,
            "go"           => true,
            "deadline_utc" => (new \DateTimeImmutable("+1 hour"))->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        // In a real system: $broker->publish($topic, $payload, qos: 2, retain: false)
        // For now: log it
        file_put_contents(
            __DIR__ . "/../../storage/mqtt.log",
            "[" . date("Y-m-d H:i:s") . "] PUBLISH $topic $payload\n",
            FILE_APPEND | LOCK_EX
        );

        echo "  OTA command published for {$this->machineId}\n";
    }

    public function toArray(): array {
        return [
            "machine_id"       => $this->machineId,
            "firmware_version" => $this->firmwareVersion,
            "firmware_url"     => $this->firmwareUrl,
            "sha256"           => $this->sha256,
        ];
    }

    public function getName(): string   { return "ota_update"; }
    public function maxAttempts(): int  { return 5; }   // OTA is critical — retry more
    public function retryDelay(): int   { return 30; }  // Wait longer between OTA retries
}
```

```php
<?php
// src/Jobs/GenerateReportJob.php
declare(strict_types=1);

namespace App\Jobs;

use App\Queue\AbstractJob;
use App\Services\FleetService;

class GenerateReportJob extends AbstractJob {
    public function __construct(
        private readonly int    $requestedByUserId,
        private readonly string $reportType,
        private readonly array  $filters = [],
    ) {}

    public function handle(): void {
        echo "  Generating {$this->reportType} report for user {$this->requestedByUserId}\n";

        // Simulate report generation (slow operation)
        sleep(2);

        $report = [
            "type"       => $this->reportType,
            "generated"  => date("Y-m-d H:i:s"),
            "filters"    => $this->filters,
            "rows"       => random_int(50, 500),
        ];

        $filename = "report_{$this->reportType}_" . date("Ymd_His") . ".json";
        file_put_contents(
            __DIR__ . "/../../storage/reports/$filename",
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        echo "  Report saved: $filename\n";

        // In production: notify user via email or push notification
    }

    public function toArray(): array {
        return [
            "user_id"     => $this->requestedByUserId,
            "report_type" => $this->reportType,
            "filters"     => $this->filters,
        ];
    }

    public function getName(): string { return "generate_report"; }
    public function maxAttempts(): int { return 2; }
}
```

## Part 3 — The queue

```php
<?php
// src/Queue/RedisQueue.php
declare(strict_types=1);

namespace App\Queue;

class RedisQueue {
    private const QUEUE_KEY      = "jobs:pending";
    private const PROCESSING_KEY = "jobs:processing";
    private const FAILED_KEY     = "jobs:failed";
    private const DELAYED_KEY    = "jobs:delayed";

    // Maps job name → class — registry for deserialisation
    private array $jobRegistry = [
        "send_email"      => \App\Jobs\SendEmailJob::class,
        "ota_update"      => \App\Jobs\OtaUpdateJob::class,
        "generate_report" => \App\Jobs\GenerateReportJob::class,
    ];

    public function __construct(private readonly \Redis $redis) {}

    // Push a job onto the queue
    public function push(Job $job): string {
        $id      = bin2hex(random_bytes(16));
        $payload = json_encode([
            "id"           => $id,
            "name"         => $job->getName(),
            "class"        => get_class($job),
            "data"         => $job->toArray(),
            "attempts"     => 0,
            "max_attempts" => $job->maxAttempts(),
            "retry_delay"  => $job->retryDelay(),
            "queued_at"    => microtime(true),
        ], JSON_THROW_ON_ERROR);

        $this->redis->rPush(self::QUEUE_KEY, $payload);

        echo "  Queued job [{$job->getName()}] id=$id\n";
        return $id;
    }

    // Push a job to run after a delay (seconds)
    public function pushDelayed(Job $job, int $delaySeconds): string {
        $id      = bin2hex(random_bytes(16));
        $runAt   = time() + $delaySeconds;

        $payload = json_encode([
            "id"           => $id,
            "name"         => $job->getName(),
            "class"        => get_class($job),
            "data"         => $job->toArray(),
            "attempts"     => 0,
            "max_attempts" => $job->maxAttempts(),
            "retry_delay"  => $job->retryDelay(),
            "queued_at"    => microtime(true),
            "run_at"       => $runAt,
        ], JSON_THROW_ON_ERROR);

        // Sorted set — score = timestamp to run at
        $this->redis->zAdd(self::DELAYED_KEY, $runAt, $payload);

        echo "  Delayed job [{$job->getName()}] id=$id runs in {$delaySeconds}s\n";
        return $id;
    }

    // Move due delayed jobs to the main queue
    public function promoteDelayed(): int {
        $now  = time();
        $jobs = $this->redis->zRangeByScore(self::DELAYED_KEY, "-inf", (string)$now);

        if (empty($jobs)) return 0;

        $count = 0;
        foreach ($jobs as $payload) {
            $this->redis->multi();
            $this->redis->rPush(self::QUEUE_KEY, $payload);
            $this->redis->zRem(self::DELAYED_KEY, $payload);
            $this->redis->exec();
            $count++;
        }

        return $count;
    }

    // Pop and atomically move to processing list
    public function pop(): ?array {
        // Promote any due delayed jobs first
        $this->promoteDelayed();

        // Atomic pop from pending → processing
        $payload = $this->redis->lMove(
            self::QUEUE_KEY,
            self::PROCESSING_KEY,
            "LEFT",
            "RIGHT"
        );

        if ($payload === false || $payload === null) return null;

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    // Mark a job as successfully completed
    public function complete(array $job): void {
        $this->removeFromProcessing($job);
        echo "  ✓ Job [{$job['name']}] {$job['id']} completed\n";
    }

    // Retry a failed job
    public function retry(array $job, \Throwable $error): void {
        $this->removeFromProcessing($job);

        $job["attempts"]++;
        $job["last_error"] = $error->getMessage();

        if ($job["attempts"] >= $job["max_attempts"]) {
            // Exhausted retries — move to dead letter queue
            $this->deadLetter($job, $error);
            return;
        }

        // Re-queue with delay
        $delay = $job["retry_delay"] * $job["attempts"];   // backoff increases per attempt
        $job["run_at"] = time() + $delay;

        $payload = json_encode($job, JSON_THROW_ON_ERROR);
        $this->redis->zAdd(self::DELAYED_KEY, $job["run_at"], $payload);

        echo "  ↻ Job [{$job['name']}] retry {$job['attempts']}/{$job['max_attempts']}"
           . " in {$delay}s: {$error->getMessage()}\n";
    }

    // Move to dead letter queue — needs human intervention
    public function deadLetter(array $job, \Throwable $error): void {
        $job["failed_at"]    = date("Y-m-d H:i:s");
        $job["final_error"]  = $error->getMessage();
        $job["trace"]        = $error->getTraceAsString();

        $this->redis->rPush(self::FAILED_KEY, json_encode($job, JSON_THROW_ON_ERROR));

        echo "  ✗ Job [{$job['name']}] {$job['id']} dead-lettered after"
           . " {$job['attempts']} attempts: {$error->getMessage()}\n";

        // In production: alert on-call, send email, increment error metric
    }

    // Get queue depths
    public function depths(): array {
        return [
            "pending"    => $this->redis->lLen(self::QUEUE_KEY),
            "processing" => $this->redis->lLen(self::PROCESSING_KEY),
            "delayed"    => $this->redis->zCard(self::DELAYED_KEY),
            "failed"     => $this->redis->lLen(self::FAILED_KEY),
        ];
    }

    // Get failed jobs for admin inspection
    public function getFailedJobs(int $limit = 20): array {
        $raw = $this->redis->lRange(self::FAILED_KEY, 0, $limit - 1);
        return array_map(
            fn($j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR),
            $raw
        );
    }

    // Requeue a failed job for another attempt
    public function requeueFailed(string $jobId): bool {
        $raw = $this->redis->lRange(self::FAILED_KEY, 0, -1);

        foreach ($raw as $payload) {
            $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if ($job["id"] === $jobId) {
                $job["attempts"] = 0;
                unset($job["failed_at"], $job["final_error"], $job["trace"]);

                $this->redis->lRem(self::FAILED_KEY, $payload, 1);
                $this->redis->rPush(self::QUEUE_KEY, json_encode($job, JSON_THROW_ON_ERROR));
                return true;
            }
        }

        return false;
    }

    private function removeFromProcessing(array $job): void {
        // Find and remove the exact payload from processing list
        $raw = $this->redis->lRange(self::PROCESSING_KEY, 0, -1);
        foreach ($raw as $payload) {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if ($decoded["id"] === $job["id"]) {
                $this->redis->lRem(self::PROCESSING_KEY, $payload, 1);
                return;
            }
        }
    }

    // Reconstruct a Job object from its serialised data
    public function reconstruct(array $jobData): Job {
        $class = $jobData["class"];
        $data  = $jobData["data"];

        if (!class_exists($class)) {
            throw new \RuntimeException("Unknown job class: $class");
        }

        // Use named arguments from the job's toArray() output
        return new $class(...$data);
    }
}
```

## Part 4 — The worker

```php
<?php
// worker.php — run this from CLI: php worker.php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use Dotenv\Dotenv;
use App\Queue\RedisQueue;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Ensure storage directories exist
@mkdir(__DIR__ . "/storage/reports", 0750, true);

// Set up Redis
$redis = new Redis();
$redis->connect($_ENV["REDIS_HOST"] ?? "127.0.0.1", (int)($_ENV["REDIS_PORT"] ?? 6379));

$queue = new RedisQueue($redis);

echo "Worker started. Listening for jobs...\n";
echo "Press Ctrl+C to stop.\n\n";

// Handle signals for graceful shutdown
$running = true;
pcntl_signal(SIGINT,  function() use (&$running) { $running = false; echo "\nShutting down...\n"; });
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });

// Main worker loop
while ($running) {
    // Process pending signals
    pcntl_signal_dispatch();

    // Try to get a job
    $jobData = $queue->pop();

    if ($jobData === null) {
        // No jobs — sleep and try again
        sleep(1);
        continue;
    }

    $name    = $jobData["name"];
    $id      = $jobData["id"];
    $attempt = $jobData["attempts"] + 1;

    echo "[" . date("H:i:s") . "] Processing [{$name}] id=$id (attempt $attempt)\n";

    try {
        // Reconstruct the job object and run it
        $job = $queue->reconstruct($jobData);
        $job->handle();
        $queue->complete($jobData);

    } catch (\Throwable $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        $queue->retry($jobData, $e);
    }

    // Brief pause between jobs — prevents hammering when queue is full
    usleep(100_000);   // 100ms
}

echo "Worker stopped cleanly.\n";
```

## Part 5 — Dispatching jobs from your web app

```php
<?php
// src/Queue/Dispatcher.php
declare(strict_types=1);

namespace App\Queue;

class Dispatcher {
    private static ?RedisQueue $queue = null;

    public static function setQueue(RedisQueue $queue): void {
        self::$queue = $queue;
    }

    public static function dispatch(Job $job): string {
        if (self::$queue === null) {
            throw new \RuntimeException("Queue not configured.");
        }
        return self::$queue->push($job);
    }

    public static function dispatchDelayed(Job $job, int $delaySeconds): string {
        if (self::$queue === null) {
            throw new \RuntimeException("Queue not configured.");
        }
        return self::$queue->pushDelayed($job, $delaySeconds);
    }
}
```

```php
<?php
// In Application::__construct() — wire up the dispatcher

$redis = new \Redis();
$redis->connect($_ENV["REDIS_HOST"] ?? "127.0.0.1");
$queue = new \App\Queue\RedisQueue($redis);
\App\Queue\Dispatcher::setQueue($queue);
```

```php
<?php
// In a controller — dispatch jobs instead of doing work synchronously

use App\Jobs\SendEmailJob;
use App\Jobs\OtaUpdateJob;
use App\Jobs\GenerateReportJob;
use App\Queue\Dispatcher;

// Instead of sending email during the request:
Dispatcher::dispatch(new SendEmailJob(
    to:      "operator@factory.local",
    subject: "Machine vend-002 fault alert",
    body:    "Machine vend-002 at Floor 2 has reported a fault.",
));

// Trigger OTA update — runs in background, not blocking the API response
Dispatcher::dispatch(new OtaUpdateJob(
    machineId:       "vend-001",
    firmwareVersion: "2.5.0",
    firmwareUrl:     "https://updates.example.com/fw/2.5.0.bin",
    sha256:          "a3f8d91c...",
));

// Schedule a report for 5 minutes from now
Dispatcher::dispatchDelayed(
    new GenerateReportJob(
        requestedByUserId: $userId,
        reportType:        "inventory",
        filters:           ["status" => "online"],
    ),
    delaySeconds: 300
);
```

## Part 6 — Admin queue dashboard

```php
<?php
// handlers/admin/queue.php
declare(strict_types=1);

requireLogin();

$redis = new \Redis();
$redis->connect($_ENV["REDIS_HOST"] ?? "127.0.0.1");
$queue = new \App\Queue\RedisQueue($redis);

$depths     = $queue->depths();
$failedJobs = $queue->getFailedJobs(20);

// Handle requeue action
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verifyCsrf();
    $action = $_POST["action"] ?? "";
    $jobId  = $_POST["job_id"] ?? "";

    if ($action === "requeue" && $jobId) {
        if ($queue->requeueFailed($jobId)) {
            flashSet("success", "Job $jobId requeued.");
        } else {
            flashSet("error", "Job $jobId not found in failed queue.");
        }
    }

    header("Location: /admin/queue");
    exit;
}

ob_start();
require __DIR__ . "/../../templates/admin/queue.html.php";
$content = ob_get_clean();
$title   = "Queue Dashboard";
require __DIR__ . "/../../templates/layout.php";
```

```php
<?php
// templates/admin/queue.html.php
declare(strict_types=1);
?>
<h1>Queue Dashboard</h1>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin:1.5rem 0">
    <?php foreach ($depths as $name => $count): ?>
        <div style="padding:1rem;border:1px solid #ddd;border-radius:6px;text-align:center">
            <div style="font-size:2rem;font-weight:600;color:<?= $name === 'failed' ? '#dc3545' : '#333' ?>">
                <?= (int)$count ?>
            </div>
            <div style="color:#666;font-size:.875rem"><?= $e(ucfirst($name)) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($failedJobs)): ?>
    <h2>Failed Jobs</h2>
    <table style="width:100%;border-collapse:collapse">
        <thead>
            <tr>
                <th>Job</th><th>Attempts</th>
                <th>Failed At</th><th>Error</th><th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($failedJobs as $job): ?>
            <tr style="border-bottom:1px solid #eee">
                <td><?= $e($job["name"]) ?><br>
                    <small style="color:#999"><?= $e(substr($job["id"], 0, 12)) ?>...</small>
                </td>
                <td style="text-align:center"><?= (int)$job["attempts"] ?></td>
                <td><?= $e($job["failed_at"] ?? "—") ?></td>
                <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis">
                    <small><?= $e($job["final_error"] ?? "—") ?></small>
                </td>
                <td>
                    <form method="POST" action="/admin/queue">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="requeue">
                        <input type="hidden" name="job_id" value="<?= $e($job["id"]) ?>">
                        <button type="submit" class="btn">Retry</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No failed jobs.</p>
<?php endif; ?>
```

---

## Today's exercise

![[Pasted image 20260603102001.png]]

The OTA stretch goal is the most architecturally satisfying exercise in the course — your PHP API receives an HTTP request, dispatches a background job, the worker picks it up and writes the MQTT payload in exactly the format your C daemon's `ota_handle_update()` expects. You've just connected the two systems you've been building in parallel. The 202 Accepted response pattern is also the correct way to expose any long-running operation as an API — the alternative (blocking until OTA completes) would mean the HTTP connection stays open for minutes, which browsers and load balancers will kill.

Run `php worker.php` in one terminal and keep it running all day. Every time you dispatch a job from your app, watch it process in real time. That feedback loop makes the queue feel real in a way that just reading about it doesn't.

Day 28 is deployment — getting this application onto a real VPS with nginx, PHP-FPM, and the worker running as a systemd service. Paste your code when ready.