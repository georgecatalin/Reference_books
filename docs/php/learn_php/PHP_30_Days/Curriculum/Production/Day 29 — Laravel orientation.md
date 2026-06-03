
You've built a router, a request/response cycle, an ORM-like repository layer, middleware, queues, migrations, and a full MVC structure — all by hand. Today you install Laravel and recognise every single piece of it. Nothing will be magic. Everything maps to something you already understand.

## The mental model — Laravel is your code, organised

```
What you built          →    What Laravel calls it
────────────────────────────────────────────────────────
Router.php              →    routes/web.php + routes/api.php
Request.php             →    Illuminate\Http\Request
Response.php            →    Illuminate\Http\Response
BaseController.php      →    App\Http\Controllers\Controller
MachineRepository.php   →    Eloquent Model (App\Models\Machine)
FleetService.php        →    Service class (same pattern)
RedisCache.php          →    Illuminate\Support\Facades\Cache
RedisQueue.php          →    Illuminate\Support\Facades\Queue
Job interface           →    Illuminate\Contracts\Queue\ShouldQueue
bin/migrate.php         →    php artisan migrate
worker.php              →    php artisan queue:work
bootstrap.php           →    bootstrap/app.php + Kernel
Dispatcher.php          →    dispatch() helper
verifyCsrf()            →    VerifyCsrfToken middleware
requireLogin()          →    auth middleware
```

## Part 1 — Installation

```bash
# Install Laravel installer
composer global require laravel/installer

# Create a new project
laravel new fleet-laravel
cd fleet-laravel

# Or with Composer directly
composer create-project laravel/laravel fleet-laravel
cd fleet-laravel

# Start the dev server
php artisan serve
# → http://127.0.0.1:8000
```

## Part 2 — Folder structure — mapped to what you know

```
fleet-laravel/
  app/
    Http/
      Controllers/        ← your Controllers/
      Middleware/         ← your middleware concept from Day 22 stretch
      Requests/           ← Form Request classes (validator + authorize)
    Models/               ← Eloquent models (replaces your Repositories)
    Services/             ← same as your Services/ — no change
    Jobs/                 ← same as your Jobs/ — implements ShouldQueue
    Exceptions/           ← same as your Exceptions/
  bootstrap/
    app.php               ← your Application.php
  config/
    database.php          ← DB connection settings
    cache.php             ← cache driver config
    queue.php             ← queue driver config
    mail.php              ← mailer config
  database/
    migrations/           ← your database/migrations/ — same pattern
    seeders/              ← seed data (your seed.php)
    factories/            ← test data factories (new concept)
  resources/
    views/                ← your src/Views/ — Blade templates
  routes/
    web.php               ← your router->get/post calls for HTML
    api.php               ← your router->get/post calls for API
  storage/
    app/                  ← your storage/ directory
    logs/                 ← your shared/logs/
  tests/
    Unit/                 ← same as your tests/Unit/
    Feature/              ← integration + HTTP tests
  .env                    ← same as your .env
  artisan                 ← CLI tool (like your bin/migrate.php, but much more)
```

## Part 3 — Artisan — the command line

```bash
# Run migrations
php artisan migrate

# Roll back last batch of migrations
php artisan migrate:rollback

# Create a migration
php artisan make:migration create_machines_table

# Create a model with migration, factory, seeder, controller, requests
php artisan make:model Machine -mfsc --requests

# Create a job
php artisan make:job ProcessOtaUpdate

# Create a controller
php artisan make:controller MachineController --resource

# Start queue worker
php artisan queue:work

# Start queue worker for specific queue
php artisan queue:work --queue=ota,default

# List failed jobs
php artisan queue:failed

# Retry a failed job
php artisan queue:retry {id}

# Generate application key (required after install)
php artisan key:generate

# Show all routes
php artisan route:list

# Clear all caches
php artisan optimize:clear

# Cache routes and config for production
php artisan optimize
```

## Part 4 — Eloquent — your Repository pattern built in

```php
<?php
// database/migrations/2026_06_01_000001_create_machines_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("machines", function (Blueprint $table) {
            $table->string("id", 30)->primary();
            $table->string("location", 100);
            $table->string("broker_ip", 45);
            $table->unsignedSmallInteger("port")->default(1883);
            $table->unsignedTinyInteger("slots")->default(20);
            $table->enum("status", ["online","offline","fault"])->default("offline");
            $table->timestamps();   // created_at + updated_at automatically

            $table->index("status");
        });
    }

    public function down(): void {
        Schema::dropIfExists("machines");
    }
};
```

```php
<?php
// app/Models/Machine.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Machine extends Model {
    // Primary key is not auto-incrementing integer
    protected $primaryKey  = "id";
    public    $incrementing = false;
    protected $keyType     = "string";

    // Mass-assignable fields
    protected $fillable = [
        "id", "location", "broker_ip", "port", "slots", "status",
    ];

    // Cast types automatically — no more (int)$row["slots"]
    protected $casts = [
        "port"  => "integer",
        "slots" => "integer",
    ];

    // ─── Business logic — same as your Machine class methods ──────

    public function fillPercent(): float {
        // In a real app: join with inventory table
        return 0.0;
    }

    public function isOnline(): bool {
        return $this->status === "online";
    }

    public function needsRestock(int $threshold = 30): bool {
        return $this->fillPercent() < $threshold;
    }

    // ─── Query scopes — named WHERE clauses ───────────────────────

    // Usage: Machine::online()->get()
    public function scopeOnline(Builder $query): Builder {
        return $query->where("status", "online");
    }

    public function scopeWithStatus(Builder $query, string $status): Builder {
        return $query->where("status", $status);
    }

    public function scopeNeedsRestock(Builder $query, int $threshold = 30): Builder {
        // Assuming inventory join exists
        return $query->where("fill_pct", "<", $threshold);
    }

    // ─── Relationships ────────────────────────────────────────────

    public function photos() {
        return $this->hasMany(\App\Models\MachinePhoto::class);
    }

    public function inventory() {
        return $this->hasMany(\App\Models\Inventory::class);
    }
}
```

```php
<?php
// Using Eloquent — notice how it maps to your MachineRepository methods

// findAll()
$machines = Machine::all();
$online   = Machine::online()->get();
$online   = Machine::where("status", "online")->get();

// findById() — throws ModelNotFoundException if not found
$machine = Machine::findOrFail("vend-001");

// findAll() with filter
$machines = Machine::withStatus("online")->orderBy("id")->get();

// findAll() paginated
$machines = Machine::paginate(20);      // returns LengthAwarePaginator
$machines = Machine::simplePaginate(20); // returns Paginator (prev/next only)

// save() — create
$machine = Machine::create([
    "id"       => "vend-010",
    "location" => "Rooftop",
    "broker_ip"=> "192.168.1.110",
    "status"   => "offline",
]);

// save() — update
$machine = Machine::findOrFail("vend-001");
$machine->status = "online";
$machine->save();

// Or with update()
Machine::where("id", "vend-001")->update(["status" => "online"]);

// delete()
Machine::findOrFail("vend-001")->delete();

// Eager loading — your N+1 fix from Day 26
$machines = Machine::with(["photos", "inventory"])->get();

// Aggregates
$count  = Machine::where("status", "online")->count();
$avgAge = Machine::avg("slots");

// The query builder — works like your MachineQuery class
$machines = Machine::query()
    ->where("status", "online")
    ->where("location", "like", "%Floor%")
    ->orderBy("created_at", "desc")
    ->limit(10)
    ->get();
```

## Part 5 — Routes — your Router, but declarative

```php
<?php
// routes/web.php
use App\Http\Controllers\MachineController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get("/",      fn() => view("welcome"));
Route::get("/blog",  [\App\Http\Controllers\PostController::class, "index"]);
Route::get("/post/{slug}", [\App\Http\Controllers\PostController::class, "show"]);

// Auth routes (login, register, logout — all generated)
Route::get("/login",    [LoginController::class, "showForm"])->name("login");
Route::post("/login",   [LoginController::class, "login"]);
Route::post("/logout",  [LoginController::class, "logout"])->name("logout");

// Protected routes — auth middleware applied to group
Route::middleware("auth")->group(function () {
    // Resource routes — generates all 7 RESTful routes in one line
    Route::resource("machines", MachineController::class);
    // Generates:
    // GET    /machines          → index
    // GET    /machines/create   → create
    // POST   /machines          → store
    // GET    /machines/{id}     → show
    // GET    /machines/{id}/edit → edit
    // PUT    /machines/{id}     → update
    // DELETE /machines/{id}     → destroy

    Route::get("/account", [\App\Http\Controllers\AccountController::class, "index"])
         ->name("account");
});
```

```php
<?php
// routes/api.php
use App\Http\Controllers\Api\MachineApiController;
use Illuminate\Support\Facades\Route;

// All API routes get /api prefix automatically
// Laravel's api middleware group handles stateless requests

Route::middleware("auth:sanctum")->group(function () {
    Route::apiResource("machines", MachineApiController::class);
    // Generates the same as resource() but without create and edit (no HTML forms)

    Route::get("machines/{machine}/inventory",
        [\App\Http\Controllers\Api\InventoryController::class, "index"]);

    Route::get("status", [\App\Http\Controllers\Api\StatusController::class, "index"]);
});

// Token endpoint — no auth required
Route::post("tokens", [\App\Http\Controllers\Api\TokenController::class, "create"]);
```

## Part 6 — Controllers — almost identical to yours

```php
<?php
// app/Http/Controllers/MachineController.php
namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\FleetService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MachineController extends Controller {
    public function __construct(
        private readonly FleetService $fleet
    ) {}

    // GET /machines
    public function index(Request $request): View {
        $machines = Machine::withStatus($request->get("status"))
                           ->orderBy("id")
                           ->paginate(20);

        return view("machines.index", compact("machines"));
    }

    // GET /machines/{id}
    public function show(Machine $machine): View {
        // Route model binding — Laravel finds Machine::findOrFail($id) automatically
        // If not found: 404 response, no code needed
        return view("machines.show", compact("machine"));
    }

    // GET /machines/create
    public function create(): View {
        return view("machines.form");
    }

    // POST /machines
    public function store(Request $request): RedirectResponse {
        $validated = $request->validate([
            "id"       => "required|string|min:3|max:20|unique:machines",
            "location" => "required|string|max:100",
            "broker_ip"=> "required|ip",
            "port"     => "required|integer|min:1|max:65535",
            "slots"    => "required|integer|min:1|max:200",
            "status"   => "required|in:online,offline,fault",
        ]);

        Machine::create($validated);

        return redirect()->route("machines.index")
                         ->with("success", "Machine registered.");
    }

    // GET /machines/{id}/edit
    public function edit(Machine $machine): View {
        return view("machines.form", compact("machine"));
    }

    // PUT /machines/{id}
    public function update(Request $request, Machine $machine): RedirectResponse {
        $validated = $request->validate([
            "location"  => "required|string|max:100",
            "broker_ip" => "required|ip",
            "port"      => "required|integer|min:1|max:65535",
            "status"    => "required|in:online,offline,fault",
        ]);

        $machine->update($validated);

        return redirect()->route("machines.index")
                         ->with("success", "Machine updated.");
    }

    // DELETE /machines/{id}
    public function destroy(Machine $machine): RedirectResponse {
        $machine->delete();

        return redirect()->route("machines.index")
                         ->with("success", "Machine deleted.");
    }
}
```

Route model binding is the biggest quality-of-life feature you don't have in your hand-rolled MVC. Laravel automatically calls `Machine::findOrFail($id)` and injects the model — if it doesn't exist, Laravel returns a 404 before your method even runs.

## Part 7 — Blade templates — your PHP templates with sugar

```blade
{{-- resources/views/machines/index.blade.php --}}
{{-- Extends the layout — replaces your ob_start() pattern --}}
@extends('layouts.app')

@section('title', 'Machines')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold">Machines</h1>
    <a href="{{ route('machines.create') }}" class="btn">+ Register</a>
</div>

{{-- Flash messages — Laravel stores these in session automatically --}}
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

{{-- Loops — same as your foreach, just cleaner syntax --}}
@forelse($machines as $machine)
    <div class="machine-card">
        <h2>{{ $machine->id }}</h2>        {{-- {{ }} auto-escapes — like your e() --}}
        <p>{{ $machine->location }}</p>
        <span class="badge status-{{ $machine->status }}">{{ $machine->status }}</span>

        <a href="{{ route('machines.edit', $machine) }}">Edit</a>

        {{-- DELETE form — CSRF token added automatically by @csrf --}}
        <form method="POST" action="{{ route('machines.destroy', $machine) }}">
            @csrf
            @method('DELETE')   {{-- Blade adds hidden _method field --}}
            <button type="submit">Delete</button>
        </form>
    </div>
@empty
    <p>No machines registered yet.</p>
@endforelse

{{-- Pagination links — automatic, no code needed --}}
{{ $machines->links() }}
@endsection
```

```blade
{{-- resources/views/machines/form.blade.php --}}
@extends('layouts.app')

@section('title', isset($machine) ? 'Edit Machine' : 'Register Machine')

@section('content')
<h1>{{ isset($machine) ? 'Edit ' . $machine->id : 'Register Machine' }}</h1>

<form method="POST"
      action="{{ isset($machine)
                 ? route('machines.update', $machine)
                 : route('machines.store') }}">
    @csrf
    @if(isset($machine))
        @method('PUT')
    @endif

    <label>
        Machine ID
        <input type="text"
               name="id"
               value="{{ old('id', $machine->id ?? '') }}"
               {{ isset($machine) ? 'readonly' : '' }}>
        @error('id')
            <span class="error">{{ $message }}</span>
        @enderror
    </label>

    <label>
        Location
        <input type="text"
               name="location"
               value="{{ old('location', $machine->location ?? '') }}">
        @error('location')
            <span class="error">{{ $message }}</span>
        @enderror
    </label>

    <label>
        Status
        <select name="status">
            @foreach(['online', 'offline', 'fault'] as $s)
                <option value="{{ $s }}"
                    {{ old('status', $machine->status ?? 'offline') === $s ? 'selected' : '' }}>
                    {{ ucfirst($s) }}
                </option>
            @endforeach
        </select>
    </label>

    <button type="submit">
        {{ isset($machine) ? 'Save Changes' : 'Register Machine' }}
    </button>
</form>
@endsection
```

`{{ }}` auto-escapes — equivalent to your `e()` function. `{!! !!}` outputs raw HTML — use sparingly, only for trusted content. `old('field')` repopulates fields after validation failure — what you implemented manually on Day 10.

## Part 8 — Jobs — your Job interface, Laravel-style

```php
<?php
// app/Jobs/ProcessOtaUpdate.php
namespace App\Jobs;

use App\Models\Machine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOtaUpdate implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry settings — same as your Job interface
    public int $tries   = 5;
    public int $backoff = 30;

    public function __construct(
        public readonly Machine $machine,   // Eloquent model — serialised automatically
        public readonly string  $version,
        public readonly string  $url,
        public readonly string  $sha256,
    ) {}

    public function handle(): void {
        $topic = "deviceCluster/{$this->machine->id}/ota/update";

        $payload = json_encode([
            "job_id"       => "ota-" . bin2hex(random_bytes(4)),
            "version"      => $this->version,
            "http_url"     => $this->url,
            "sha256"       => $this->sha256,
            "go"           => true,
            "deadline_utc" => now()->addHour()->toIso8601String(),
        ]);

        // In production: resolve MqttBrokerClient from container
        // app(MqttBrokerClient::class)->publish($topic, $payload, qos: 2);

        logger()->info("OTA queued", [
            "machine" => $this->machine->id,
            "version" => $this->version,
        ]);
    }

    // Called when all retries exhausted
    public function failed(\Throwable $e): void {
        logger()->error("OTA failed permanently", [
            "machine" => $this->machine->id,
            "error"   => $e->getMessage(),
        ]);
        // Send alert email, update machine status, etc.
    }
}
```

```php
<?php
// Dispatching jobs — compare to your Dispatcher::dispatch()

// Immediately
ProcessOtaUpdate::dispatch($machine, "2.5.0", $url, $sha256);

// With delay
ProcessOtaUpdate::dispatch($machine, "2.5.0", $url, $sha256)
                ->delay(now()->addMinutes(5));

// On a specific queue
ProcessOtaUpdate::dispatch($machine, "2.5.0", $url, $sha256)
                ->onQueue("ota");

// Conditional dispatch
ProcessOtaUpdate::dispatchIf($machine->isOnline(), $machine, "2.5.0", $url, $sha256);
```

## Part 9 — Cache and config

```php
<?php
// Cache — same as your RedisCache but with a nicer API
use Illuminate\Support\Facades\Cache;

// remember() — same as your Cache interface
$machines = Cache::remember("all_machines", 60, function() {
    return Machine::all();
});

// Store
Cache::put("key", "value", 300);
Cache::forever("key", "value");

// Retrieve
$value = Cache::get("key", "default");

// Delete
Cache::forget("key");
Cache::flush();

// Tags — group related cache keys for bulk invalidation
Cache::tags(["machines", "fleet"])->put("machine:vend-001", $machine, 300);
Cache::tags("machines")->flush();   // invalidates all machine cache
```

```php
<?php
// config/queue.php — configure which driver to use
// In .env:
// QUEUE_CONNECTION=redis

// Run the worker
// php artisan queue:work redis --queue=ota,default --tries=3 --timeout=60

// In production — systemd service, same as yours:
// ExecStart=/usr/bin/php /var/www/fleet/artisan queue:work redis --sleep=3 --tries=3
```

## Part 10 — Rebuild your blog in Laravel

```bash
# Create the posts migration
php artisan make:migration create_posts_table

# Create Post model with all the scaffolding
php artisan make:model Post -mfc --requests

# Create the controller with resource methods
php artisan make:controller PostController --resource --model=Post
```

```php
<?php
// app/Models/Post.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Post extends Model {
    protected $fillable = ["title", "slug", "body", "status", "published_at", "author_id"];

    protected $casts = [
        "published_at" => "datetime",
    ];

    // Auto-generate slug when title is set
    protected static function booted(): void {
        static::creating(function (Post $post) {
            $post->slug = Str::slug($post->title);
            if (empty($post->author_id)) {
                $post->author_id = auth()->id();
            }
        });
    }

    // Scopes
    public function scopePublished($query) {
        return $query->where("status", "published")
                     ->whereNotNull("published_at")
                     ->orderBy("published_at", "desc");
    }

    // Relationships
    public function author() {
        return $this->belongsTo(User::class, "author_id");
    }
}
```

```php
<?php
// app/Http/Controllers/PostController.php
namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller {
    public function index() {
        $posts = Post::published()->with("author")->paginate(5);
        return view("posts.index", compact("posts"));
    }

    public function show(Post $post) {
        // Route model binding by slug:
        // Route::get('/post/{post:slug}', ...)
        abort_if($post->status !== "published", 404);
        return view("posts.show", compact("post"));
    }

    public function store(Request $request) {
        $validated = $request->validate([
            "title"  => "required|string|max:200",
            "body"   => "required|string",
            "status" => "required|in:draft,published",
        ]);

        if ($validated["status"] === "published") {
            $validated["published_at"] = now();
        }

        $post = auth()->user()->posts()->create($validated);

        return redirect()->route("admin.posts.index")
                         ->with("success", "Post created.");
    }
}
```

---

## Today's exercise

![[Pasted image 20260603102556.png]]



The comparison in Part A is the most important thing you'll do today. Write it out explicitly — not "Laravel has routing" but "Route::resource('machines', MachineController::class) generates 7 routes that I know by name because I registered each one manually in my Router.php on Day 22, and the auth middleware group is the same concept as the middleware array I added to routes in the Day 22 stretch goal." That level of specificity is the difference between using a framework and understanding one.

The line count comparison at the end of Part B tells you the concrete value of convention over configuration. Your Day 17 blog is probably 800–1200 lines of PHP across 20+ files. The Laravel version of the same features is probably 400–600 lines. The missing lines aren't magic — they're boilerplate that Laravel provides through conventions you now understand completely.

Tomorrow is Day 30 — the capstone. You'll ship something real that combines auth, CRUD, a REST API, at least one background job, and a third-party API integration, deployed with nginx and a systemd worker. Paste your Laravel code when ready.