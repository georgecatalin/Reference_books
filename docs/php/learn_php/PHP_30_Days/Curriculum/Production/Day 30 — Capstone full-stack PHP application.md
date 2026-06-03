
This is the final day. No new concepts. Everything you need is already in your head — 29 days of fundamentals, architecture, security, performance, queues, APIs, and deployment. Today you ship something real.

## The brief

Build a **Fleet Management Cockpit** — the PHP backend for your MQTT device daemon system. This is not a toy. It connects to what you've already built in C, uses every major skill from the course, and produces something you can actually deploy and use.

## What you're building## The schema

```sql
-- Run via: php artisan migrate

-- Laravel's default users table (from laravel/breeze or manual)
-- Add these to the existing migration:
-- remember_token, email_verified_at already included

-- machines — from your Day 11 work
CREATE TABLE machines (
    id          VARCHAR(30)       NOT NULL PRIMARY KEY,
    location    VARCHAR(100)      NOT NULL,
    cluster     VARCHAR(100)      NOT NULL DEFAULT 'deviceCluster',
    broker_ip   VARCHAR(45)       NOT NULL DEFAULT '127.0.0.1',
    port        SMALLINT UNSIGNED NOT NULL DEFAULT 1883,
    slots       TINYINT UNSIGNED  NOT NULL DEFAULT 20,
    status      ENUM('online','offline','fault') NOT NULL DEFAULT 'offline',
    fw_version  VARCHAR(32)       NULL,
    last_seen   DATETIME          NULL,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_cluster (cluster)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- telemetry — heartbeat data from your C daemon
CREATE TABLE telemetry (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id   VARCHAR(30)  NOT NULL,
    cpu_pct      TINYINT UNSIGNED NULL,
    ram_used_mb  SMALLINT UNSIGNED NULL,
    temp_cpu_c   DECIMAL(5,2) NULL,
    power_v      DECIMAL(5,2) NULL,
    uptime_s     INT UNSIGNED NULL,
    recorded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_machine_time (machine_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ota_jobs — track firmware update history
CREATE TABLE ota_jobs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id   VARCHAR(30)  NOT NULL,
    job_id       VARCHAR(64)  NOT NULL UNIQUE,
    version      VARCHAR(32)  NOT NULL,
    status       ENUM('pending','downloading','installing','success','failed')
                 NOT NULL DEFAULT 'pending',
    initiated_by INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (machine_id)   REFERENCES machines(id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## The implementation

### Models

```php
<?php
// app/Models/Machine.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Machine extends Model {
    protected $primaryKey   = "id";
    public    $incrementing = false;
    protected $keyType      = "string";

    protected $fillable = [
        "id", "location", "cluster", "broker_ip", "port",
        "slots", "status", "fw_version", "last_seen",
    ];

    protected $casts = [
        "port"      => "integer",
        "slots"     => "integer",
        "last_seen" => "datetime",
    ];

    // Scopes
    public function scopeOnline(Builder $q): Builder {
        return $q->where("status", "online");
    }

    public function scopeFault(Builder $q): Builder {
        return $q->where("status", "fault");
    }

    public function scopeStale(Builder $q, int $minutesAgo = 5): Builder {
        return $q->where("last_seen", "<", now()->subMinutes($minutesAgo))
                 ->where("status", "online");
    }

    // Relationships
    public function telemetry() {
        return $this->hasMany(Telemetry::class);
    }

    public function latestTelemetry() {
        return $this->hasOne(Telemetry::class)->latestOfMany();
    }

    public function otaJobs() {
        return $this->hasMany(OtaJob::class);
    }

    // Computed
    public function mqttTopic(string $suffix): string {
        return "{$this->cluster}/{$this->id}/$suffix";
    }

    public function isStale(int $minutesAgo = 5): bool {
        if (!$this->last_seen) return true;
        return $this->last_seen->diffInMinutes(now()) > $minutesAgo;
    }
}
```

```php
<?php
// app/Models/Telemetry.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Telemetry extends Model {
    public    $timestamps = false;

    protected $fillable = [
        "machine_id", "cpu_pct", "ram_used_mb",
        "temp_cpu_c", "power_v", "uptime_s", "recorded_at",
    ];

    protected $casts = [
        "temp_cpu_c"  => "float",
        "power_v"     => "float",
        "recorded_at" => "datetime",
    ];

    public function machine() {
        return $this->belongsTo(Machine::class);
    }
}
```

### Services

```php
<?php
// app/Services/FleetService.php
namespace App\Services;

use App\Models\Machine;
use App\Models\Telemetry;
use App\Jobs\SendAlertJob;
use App\Jobs\OtaUpdateJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FleetService {
    // Process incoming heartbeat from MQTT daemon
    // This would be called by a webhook or MQTT subscriber
    public function processHeartbeat(string $machineId, array $payload): void {
        $machine = Machine::find($machineId);

        if (!$machine) {
            Log::warning("Heartbeat from unknown machine: $machineId");
            return;
        }

        // Update machine status
        $machine->update([
            "status"    => "online",
            "last_seen" => now(),
        ]);

        // Store telemetry reading
        Telemetry::create([
            "machine_id"  => $machineId,
            "cpu_pct"     => $payload["cpu_pct"]     ?? null,
            "ram_used_mb" => $payload["ram_used_mb"] ?? null,
            "temp_cpu_c"  => $payload["temp_cpu_c"]  ?? null,
            "power_v"     => $payload["power_v"]     ?? null,
            "uptime_s"    => $payload["uptime_s"]    ?? null,
            "recorded_at" => now(),
        ]);

        // Alert if temperature critical
        if (($payload["temp_cpu_c"] ?? 0) > 90.0) {
            SendAlertJob::dispatch(
                "critical-temp@factory.local",
                "Critical temperature on $machineId",
                "CPU temperature: {$payload['temp_cpu_c']}°C"
            );
        }

        // Invalidate cache
        Cache::forget("machine:$machineId");
        Cache::forget("fleet:summary");
    }

    public function getFleetSummary(): array {
        return Cache::remember("fleet:summary", 30, function() {
            return [
                "total"   => Machine::count(),
                "online"  => Machine::online()->count(),
                "fault"   => Machine::fault()->count(),
                "offline" => Machine::where("status", "offline")->count(),
                "stale"   => Machine::stale()->count(),
            ];
        });
    }

    public function initiateOta(
        Machine $machine,
        string  $version,
        string  $url,
        string  $sha256,
        int     $userId
    ): \App\Models\OtaJob {
        $job = \App\Models\OtaJob::create([
            "machine_id"    => $machine->id,
            "job_id"        => "ota-" . bin2hex(random_bytes(8)),
            "version"       => $version,
            "status"        => "pending",
            "initiated_by"  => $userId,
        ]);

        OtaUpdateJob::dispatch($machine, $version, $url, $sha256, $job->job_id);

        return $job;
    }
}
```

### Controllers — web

```php
<?php
// app/Http/Controllers/DashboardController.php
namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\FleetService;
use App\Http\Clients\WeatherClient;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    public function __construct(
        private readonly FleetService  $fleet,
        private readonly WeatherClient $weather,
    ) {}

    public function index(): \Illuminate\View\View {
        $summary  = $this->fleet->getFleetSummary();
        $faults   = Machine::fault()->with("latestTelemetry")->get();
        $stale    = Machine::stale()->get();

        // Weather — non-critical
        $weather = rescue(
            fn() => $this->weather->current(45.47, 28.05),
            null,
            report: false
        );

        return view("dashboard", compact("summary", "faults", "stale", "weather"));
    }
}
```

```php
<?php
// app/Http/Controllers/MachineController.php
namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller {
    public function index(Request $request): \Illuminate\View\View {
        $machines = Machine::query()
            ->when($request->get("status"), fn($q, $s) => $q->where("status", $s))
            ->when($request->get("search"), fn($q, $s) =>
                $q->where("location", "like", "%$s%")
                  ->orWhere("id", "like", "%$s%"))
            ->with("latestTelemetry")
            ->orderBy("status")
            ->orderBy("id")
            ->paginate(20)
            ->withQueryString();  // preserves filter params in pagination links

        return view("machines.index", compact("machines"));
    }

    public function show(Machine $machine): \Illuminate\View\View {
        $machine->load(["latestTelemetry", "otaJobs" => fn($q) => $q->latest()->limit(5)]);

        $telemetryHistory = $machine->telemetry()
            ->latest("recorded_at")
            ->limit(50)
            ->get();

        return view("machines.show", compact("machine", "telemetryHistory"));
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse {
        $validated = $request->validate([
            "id"        => "required|string|min:3|max:30|unique:machines|regex:/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/",
            "location"  => "required|string|max:100",
            "cluster"   => "required|string|max:100",
            "broker_ip" => "required|ip",
            "port"      => "required|integer|min:1|max:65535",
            "slots"     => "required|integer|min:1|max:200",
        ]);

        Machine::create($validated);

        return redirect()->route("machines.show", $validated["id"])
                         ->with("success", "Machine registered.");
    }

    public function update(Request $request, Machine $machine): \Illuminate\Http\RedirectResponse {
        $validated = $request->validate([
            "location"  => "required|string|max:100",
            "broker_ip" => "required|ip",
            "port"      => "required|integer|min:1|max:65535",
            "status"    => "required|in:online,offline,fault",
        ]);

        $machine->update($validated);

        return redirect()->route("machines.show", $machine)
                         ->with("success", "Machine updated.");
    }

    public function destroy(Machine $machine): \Illuminate\Http\RedirectResponse {
        $machine->delete();
        return redirect()->route("machines.index")->with("success", "Machine deleted.");
    }
}
```

### Controllers — API

```php
<?php
// app/Http/Controllers/Api/TelemetryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\FleetService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TelemetryController extends Controller {
    public function __construct(private readonly FleetService $fleet) {}

    // POST /api/machines/{machine}/telemetry
    // Called by the MQTT broker webhook or a bridge script
    public function store(Request $request, Machine $machine): JsonResponse {
        $validated = $request->validate([
            "cpu_pct"     => "nullable|integer|min:0|max:100",
            "ram_used_mb" => "nullable|integer|min:0",
            "temp_cpu_c"  => "nullable|numeric|min:-40|max:200",
            "power_v"     => "nullable|numeric|min:0|max:50",
            "uptime_s"    => "nullable|integer|min:0",
        ]);

        $this->fleet->processHeartbeat($machine->id, $validated);

        return response()->json(["status" => "ok"], 200);
    }

    // GET /api/machines/{machine}/telemetry
    public function index(Machine $machine): JsonResponse {
        $history = $machine->telemetry()
            ->latest("recorded_at")
            ->limit(100)
            ->get(["recorded_at", "cpu_pct", "temp_cpu_c", "power_v", "uptime_s"]);

        return response()->json([
            "data" => $history,
            "meta" => ["machine_id" => $machine->id, "count" => $history->count()],
        ]);
    }
}
```

```php
<?php
// app/Http/Controllers/Api/OtaController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\FleetService;
use App\Http\Clients\GitHubClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OtaController extends Controller {
    public function __construct(
        private readonly FleetService $fleet,
        private readonly GitHubClient $github,
    ) {}

    // GET /api/firmware/latest
    public function latestFirmware(): JsonResponse {
        $release = rescue(
            fn() => $this->github->getLatestRelease(
                config("fleet.firmware_repo_owner"),
                config("fleet.firmware_repo_name")
            ),
            null
        );

        if (!$release) {
            return response()->json(["error" => "Could not fetch firmware info"], 503);
        }

        return response()->json([
            "data" => [
                "version"      => $release["tag_name"],
                "published_at" => $release["published_at"],
                "url"          => $release["assets"][0]["browser_download_url"] ?? null,
                "notes"        => $release["body"] ?? null,
            ],
        ]);
    }

    // POST /api/machines/{machine}/ota
    public function initiate(Request $request, Machine $machine): JsonResponse {
        $validated = $request->validate([
            "version" => "required|string|max:32",
            "url"     => "required|url|max:512",
            "sha256"  => "required|string|size:64",
        ]);

        $otaJob = $this->fleet->initiateOta(
            $machine,
            $validated["version"],
            $validated["url"],
            $validated["sha256"],
            $request->user()->id,
        );

        return response()->json([
            "data" => [
                "job_id"     => $otaJob->job_id,
                "machine_id" => $machine->id,
                "version"    => $validated["version"],
                "status"     => "pending",
            ],
        ], 202);  // 202 Accepted — queued, not done yet
    }

    // GET /api/machines/{machine}/ota/{jobId}
    public function status(Machine $machine, string $jobId): JsonResponse {
        $job = $machine->otaJobs()->where("job_id", $jobId)->firstOrFail();

        return response()->json(["data" => $job]);
    }
}
```

### Background jobs

```php
<?php
// app/Jobs/OtaUpdateJob.php
namespace App\Jobs;

use App\Models\Machine;
use App\Models\OtaJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OtaUpdateJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public readonly Machine $machine,
        public readonly string  $version,
        public readonly string  $url,
        public readonly string  $sha256,
        public readonly string  $otaJobId,
    ) {}

    public function handle(): void {
        // Update OTA job status
        OtaJob::where("job_id", $this->otaJobId)->update(["status" => "downloading"]);

        // Build MQTT payload — exact format your C daemon expects
        $topic   = $this->machine->mqttTopic("ota/update");
        $payload = [
            "job_id"       => $this->otaJobId,
            "version"      => $this->version,
            "http_url"     => $this->url,
            "sha256"       => $this->sha256,
            "go"           => true,
            "deadline_utc" => now()->addHour()->toIso8601String(),
        ];

        // In production: app(MqttBrokerClient::class)->publish($topic, $payload, qos: 2)
        // For now: log the command
        Log::channel("mqtt")->info("OTA publish", [
            "topic"   => $topic,
            "payload" => $payload,
        ]);

        // Write to file for your C daemon to pick up (alternative approach)
        $logLine = json_encode([
            "timestamp" => now()->toIso8601String(),
            "topic"     => $topic,
            "payload"   => $payload,
        ]) . "\n";

        file_put_contents(
            storage_path("logs/mqtt-commands.log"),
            $logLine,
            FILE_APPEND | LOCK_EX
        );

        OtaJob::where("job_id", $this->otaJobId)->update(["status" => "installing"]);

        Log::info("OTA command published", [
            "machine" => $this->machine->id,
            "version" => $this->version,
            "job_id"  => $this->otaJobId,
        ]);
    }

    public function failed(\Throwable $e): void {
        OtaJob::where("job_id", $this->otaJobId)->update(["status" => "failed"]);

        Log::error("OTA job failed permanently", [
            "machine" => $this->machine->id,
            "job_id"  => $this->otaJobId,
            "error"   => $e->getMessage(),
        ]);

        SendAlertJob::dispatch(
            config("fleet.alert_email"),
            "OTA Failed: {$this->machine->id}",
            "OTA update to v{$this->version} failed after {$this->tries} attempts.\nError: {$e->getMessage()}"
        );
    }
}
```

```php
<?php
// app/Jobs/SendAlertJob.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAlertJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $body,
    ) {}

    public function handle(): void {
        // In production: Mail::to($this->to)->send(new AlertMail($this->subject, $this->body));
        Log::channel("alerts")->info("ALERT", [
            "to"      => $this->to,
            "subject" => $this->subject,
            "body"    => $this->body,
        ]);

        echo "Alert sent to {$this->to}: {$this->subject}\n";
    }
}
```

```php
<?php
// app/Jobs/FirmwareCheckJob.php
namespace App\Jobs;

use App\Http\Clients\GitHubClient;
use App\Models\Machine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FirmwareCheckJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly GitHubClient $github) {}

    public function handle(): void {
        $release = $this->github->getLatestRelease(
            config("fleet.firmware_repo_owner"),
            config("fleet.firmware_repo_name")
        );

        if (!$release) return;

        $latestVersion = $release["tag_name"];

        // Find machines running old firmware
        $outdated = Machine::online()
            ->where("fw_version", "!=", $latestVersion)
            ->whereNotNull("fw_version")
            ->get();

        foreach ($outdated as $machine) {
            Log::info("Firmware update available", [
                "machine"  => $machine->id,
                "current"  => $machine->fw_version,
                "latest"   => $latestVersion,
            ]);

            // Don't auto-update — queue an alert instead
            SendAlertJob::dispatch(
                config("fleet.alert_email"),
                "Firmware update available for {$machine->id}",
                "Machine {$machine->id} is running {$machine->fw_version}. " .
                "Latest: $latestVersion."
            );
        }

        // Cache the latest version for the dashboard
        Cache::put("firmware:latest", [
            "version"      => $latestVersion,
            "published_at" => $release["published_at"],
            "checked_at"   => now()->toIso8601String(),
        ], 3600);
    }
}
```

### Key Blade templates

```blade
{{-- resources/views/dashboard.blade.php --}}
@extends('layouts.app')

@section('title', 'Fleet Dashboard')

@section('content')
<div class="grid grid-cols-4 gap-4 mb-8">
    @foreach(['total' => 'Total', 'online' => 'Online', 'fault' => 'Fault', 'offline' => 'Offline'] as $key => $label)
    <div class="stat-card {{ $key === 'fault' && $summary[$key] > 0 ? 'stat-card--alert' : '' }}">
        <div class="stat-value">{{ $summary[$key] }}</div>
        <div class="stat-label">{{ $label }}</div>
    </div>
    @endforeach
</div>

@if($faults->isNotEmpty())
<section class="mb-8">
    <h2>⚠ Machines in Fault</h2>
    @foreach($faults as $machine)
    <div class="fault-row">
        <a href="{{ route('machines.show', $machine) }}">{{ $machine->id }}</a>
        <span>{{ $machine->location }}</span>
        @if($machine->latestTelemetry)
            <span>{{ $machine->latestTelemetry->temp_cpu_c }}°C</span>
        @endif
        <a href="{{ route('machines.show', $machine) }}" class="btn btn-sm">View</a>
    </div>
    @endforeach
</section>
@endif

@if($stale->isNotEmpty())
<section class="mb-8">
    <h2>⚪ Stale Devices (no heartbeat &gt;5 min)</h2>
    @foreach($stale as $machine)
    <div class="stale-row">
        <a href="{{ route('machines.show', $machine) }}">{{ $machine->id }}</a>
        <span>Last seen: {{ $machine->last_seen?->diffForHumans() ?? 'never' }}</span>
    </div>
    @endforeach
</section>
@endif

@if($weather)
<section class="weather-widget">
    <h3>Current conditions — Galați</h3>
    <p>{{ $weather['temperature'] }}°C · {{ $weather['windspeed'] }} km/h wind</p>
</section>
@endif
@endsection
```

```blade
{{-- resources/views/machines/show.blade.php --}}
@extends('layouts.app')

@section('title', $machine->id)

@section('content')
<div class="flex justify-between items-start mb-6">
    <div>
        <h1>{{ $machine->id }}</h1>
        <p class="text-muted">{{ $machine->location }} · {{ $machine->cluster }}</p>
    </div>
    <div class="flex gap-2">
        <span class="badge status-{{ $machine->status }}">{{ $machine->status }}</span>
        <a href="{{ route('machines.edit', $machine) }}" class="btn btn-sm">Edit</a>
    </div>
</div>

{{-- Latest telemetry --}}
@if($machine->latestTelemetry)
<div class="telemetry-grid mb-6">
    <div class="metric">
        <span class="metric-value">{{ $machine->latestTelemetry->cpu_pct }}%</span>
        <span class="metric-label">CPU</span>
    </div>
    <div class="metric">
        <span class="metric-value">{{ $machine->latestTelemetry->temp_cpu_c }}°C</span>
        <span class="metric-label">Temperature</span>
    </div>
    <div class="metric">
        <span class="metric-value">{{ $machine->latestTelemetry->power_v }}V</span>
        <span class="metric-label">Power</span>
    </div>
    <div class="metric">
        <span class="metric-value">{{ $machine->fw_version ?? '—' }}</span>
        <span class="metric-label">Firmware</span>
    </div>
</div>
@endif

{{-- OTA trigger form --}}
@if($latestFirmware = Cache::get('firmware:latest'))
<section class="ota-panel mb-6">
    <h2>Firmware Update</h2>
    <p>Latest available: <strong>{{ $latestFirmware['version'] }}</strong>
       ({{ \Carbon\Carbon::parse($latestFirmware['published_at'])->diffForHumans() }})</p>

    @if($machine->fw_version !== $latestFirmware['version'])
    <form method="POST"
          action="{{ route('api.machines.ota', $machine) }}"
          onsubmit="return confirm('Initiate OTA update to {{ $latestFirmware['version'] }}?')">
        @csrf
        <input type="hidden" name="version" value="{{ $latestFirmware['version'] }}">
        <input type="hidden" name="url"     value="{{ $latestFirmware['url'] ?? '' }}">
        <input type="hidden" name="sha256"  value="{{ $latestFirmware['sha256'] ?? '' }}">
        <button type="submit" class="btn btn-primary">
            Update to {{ $latestFirmware['version'] }}
        </button>
    </form>
    @else
        <p class="text-success">✓ Up to date</p>
    @endif
</section>
@endif

{{-- Recent OTA history --}}
@if($machine->otaJobs->isNotEmpty())
<section class="mb-6">
    <h2>OTA History</h2>
    <table>
        <thead><tr><th>Job</th><th>Version</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        @foreach($machine->otaJobs as $job)
        <tr>
            <td><code>{{ substr($job->job_id, 0, 12) }}...</code></td>
            <td>{{ $job->version }}</td>
            <td><span class="badge ota-{{ $job->status }}">{{ $job->status }}</span></td>
            <td>{{ $job->created_at->diffForHumans() }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif
@endsection
```

### Routes

```php
<?php
// routes/web.php
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MachineController;
use Illuminate\Support\Facades\Route;

Route::get("/", fn() => redirect()->route("dashboard"));

// Auth — use Breeze or manual
Route::get("/login",   [\App\Http\Controllers\Auth\LoginController::class, "form"])->name("login");
Route::post("/login",  [\App\Http\Controllers\Auth\LoginController::class, "login"]);
Route::post("/logout", [\App\Http\Controllers\Auth\LoginController::class, "logout"])->name("logout");

Route::middleware("auth")->group(function() {
    Route::get("/dashboard", [DashboardController::class, "index"])->name("dashboard");
    Route::resource("machines", MachineController::class);

    // OTA trigger from web UI
    Route::post("machines/{machine}/ota", [\App\Http\Controllers\OtaController::class, "initiate"])
         ->name("api.machines.ota");
});

// routes/api.php
Route::middleware("auth:sanctum")->group(function() {
    Route::apiResource("machines", \App\Http\Controllers\Api\MachineController::class);

    Route::get("machines/{machine}/telemetry",
        [\App\Http\Controllers\Api\TelemetryController::class, "index"]);
    Route::post("machines/{machine}/telemetry",
        [\App\Http\Controllers\Api\TelemetryController::class, "store"]);

    Route::post("machines/{machine}/ota",
        [\App\Http\Controllers\Api\OtaController::class, "initiate"]);
    Route::get("machines/{machine}/ota/{jobId}",
        [\App\Http\Controllers\Api\OtaController::class, "status"]);

    Route::get("firmware/latest",
        [\App\Http\Controllers\Api\OtaController::class, "latestFirmware"]);

    Route::get("status",
        [\App\Http\Controllers\Api\StatusController::class, "index"]);
});

Route::post("api/tokens",
    [\App\Http\Controllers\Api\TokenController::class, "create"]);
```

---

## The acceptance checklist## Build sequence — do it in this order

```
1. Schema + migrations  (30 min)
   Create all tables, run migrate, verify in MySQL client

2. Models + relationships  (30 min)
   Machine, Telemetry, OtaJob — add scopes, casts, relationships

3. Auth  (20 min)
   Laravel Breeze or manual — just get login/logout working

4. Machine CRUD web  (45 min)
   Controller + routes + Blade templates
   Get the list, create, edit, delete flow working in browser

5. Telemetry API endpoint  (30 min)
   POST /api/machines/{id}/telemetry
   Test with curl — simulate a heartbeat from your C daemon

6. OTA job + queue  (45 min)
   OtaUpdateJob + dispatch from API endpoint
   Verify worker picks it up and writes to mqtt-commands.log

7. Dashboard  (30 min)
   Summary counts, fault list, stale devices, weather widget

8. GitHub firmware API  (20 min)
   GET /api/firmware/latest — verify with curl

9. Caching + ETags  (20 min)
   Cache fleet summary in Redis, add ETag to machine list API

10. Tests  (30 min)
    4 tests minimum — model unit + feature HTTP tests

11. Health endpoint  (15 min)
    /health — DB + Redis + storage checks

12. Systemd worker  (15 min)
    fleet-worker.service — enable, start, verify, kill-test
```

Total estimated time: 5–6 hours for a focused build session.

---

## What you've built over 30 days

```
Phase 1 (Days 1–8):   PHP fundamentals — types, control flow,
                       functions, arrays, strings, files, errors

Phase 2 (Days 9–17):  Web applications — HTTP, forms, MySQL/PDO,
                       CRUD, sessions, auth, security, uploads, blog

Phase 3 (Days 18–25): Architecture — OOP, inheritance, interfaces,
                       traits, Composer, MVC, REST API, external APIs, tests

Phase 4 (Days 26–30): Production — Redis cache, background queues,
                       deployment, Laravel, capstone
```

The capstone connects the two codebases you've been building in parallel. Your C daemon publishes heartbeats and receives OTA commands over MQTT. Your PHP backend stores those heartbeats, serves the dashboard, triggers OTA jobs through a queue, and monitors firmware versions via GitHub. The MQTT payload format your `OtaUpdateJob` writes to `mqtt-commands.log` is exactly what your C daemon's `ota_handle_update()` function parses. You built both ends of the same system.

Submit your capstone when it's done. Paste the code you're least confident about — typically the FleetService, the OTA job, or the telemetry endpoint — and I'll review it in detail.