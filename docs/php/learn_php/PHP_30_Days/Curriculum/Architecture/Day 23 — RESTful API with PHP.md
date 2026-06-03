
Your MVC application serves HTML. Today you add a JSON API layer on top of the same services and repositories — same business logic, different output format. This is the architecture that powers your MQTT dashboard's backend, the Cockpit web interface from your PDF, and every modern fleet management system.

## The mental model — REST constraints

```
REST = Representational State Transfer
Six constraints that define a RESTful API:

1. Client-Server     — frontend and backend are separate
2. Stateless         — each request contains all information needed
                       no sessions, no cookies — use tokens instead
3. Cacheable         — responses declare if they can be cached
4. Uniform Interface — consistent URL structure and HTTP method meaning
5. Layered System    — client doesn't know if it's talking to server or proxy
6. Code on Demand    — optional, rarely used
```

The practical result is a URL and method convention:

```
Resource: machines

GET    /api/machines          → list all machines
POST   /api/machines          → create a machine
GET    /api/machines/{id}     → get one machine
PUT    /api/machines/{id}     → replace a machine entirely
PATCH  /api/machines/{id}     → update specific fields
DELETE /api/machines/{id}     → delete a machine

Nested resource: inventory belonging to a machine
GET    /api/machines/{id}/inventory        → list inventory for a machine
POST   /api/machines/{id}/inventory        → add inventory item
DELETE /api/machines/{id}/inventory/{slot} → remove one slot
```

## HTTP status codes — use them correctly

```
2xx Success:
  200 OK              — GET, PUT, PATCH succeeded
  201 Created         — POST succeeded, resource created
  204 No Content      — DELETE succeeded, nothing to return

4xx Client errors — the request was wrong:
  400 Bad Request     — malformed JSON, missing required fields
  401 Unauthorized    — no token or token invalid
  403 Forbidden       — token valid but not allowed to do this
  404 Not Found       — resource doesn't exist
  405 Method Not Allowed — wrong HTTP method for this endpoint
  409 Conflict        — duplicate resource (machine id already exists)
  422 Unprocessable   — validation failed (fields present but invalid)

5xx Server errors — the server failed:
  500 Internal Error  — unexpected exception
  503 Service Unavail — database down, dependency unavailable
```

## API response envelope — a consistent shape

Every response, success or failure, should have a predictable structure:

```json
// Success — single resource
{
  "data": { "id": "vend-001", "location": "Floor 1", "status": "online" },
  "meta": { "timestamp": "2026-06-03T14:32:07Z" }
}

// Success — collection
{
  "data": [ {...}, {...}, {...} ],
  "meta": { "total": 3, "page": 1, "per_page": 20, "timestamp": "..." }
}

// Error
{
  "error": {
    "code":    "VALIDATION_FAILED",
    "message": "The request data is invalid.",
    "details": { "id": "ID is required.", "port": "Port must be 1–65535." }
  },
  "meta": { "timestamp": "2026-06-03T14:32:07Z" }
}
```

Consistent envelopes mean API clients can always find data in `response.data` and errors in `response.error`. Never return a bare array or a bare error string.

## The API base controller

```php
<?php
// src/Http/Controllers/Api/ApiController.php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

abstract class ApiController {
    protected function success(
        mixed  $data,
        int    $status = 200,
        array  $meta   = []
    ): Response {
        return (new Response())->json([
            "data" => $data,
            "meta" => array_merge(
                ["timestamp" => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
                $meta
            ),
        ], $status);
    }

    protected function created(mixed $data): Response {
        return $this->success($data, 201);
    }

    protected function noContent(): Response {
        return (new Response())->status(204);
    }

    protected function error(
        string $message,
        int    $status  = 400,
        string $code    = "BAD_REQUEST",
        array  $details = []
    ): Response {
        $body = [
            "error" => [
                "code"    => $code,
                "message" => $message,
            ],
            "meta" => [
                "timestamp" => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ];

        if (!empty($details)) {
            $body["error"]["details"] = $details;
        }

        return (new Response())->json($body, $status);
    }

    protected function notFound(string $message = "Resource not found."): Response {
        return $this->error($message, 404, "NOT_FOUND");
    }

    protected function validationError(array $errors): Response {
        return $this->error(
            "The request data is invalid.",
            422,
            "VALIDATION_FAILED",
            $errors
        );
    }

    protected function unauthorized(string $message = "Authentication required."): Response {
        return $this->error($message, 401, "UNAUTHORIZED");
    }

    protected function forbidden(string $message = "Access denied."): Response {
        return $this->error($message, 403, "FORBIDDEN");
    }

    // Parse and validate JSON body — throws on malformed JSON
    protected function jsonBody(Request $request): array {
        $contentType = $request->header("Content-Type") ?? "";

        if (!str_contains($contentType, "application/json")) {
            throw new \InvalidArgumentException(
                "Content-Type must be application/json."
            );
        }

        try {
            return $request->json();
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Invalid JSON: " . $e->getMessage());
        }
    }

    // Authenticate via Bearer token — returns user_id or throws
    protected function authenticate(Request $request): int {
        $header = $request->header("Authorization") ?? "";

        if (!str_starts_with($header, "Bearer ")) {
            throw new \RuntimeException("No bearer token.");
        }

        $token = substr($header, 7);
        $userId = $this->validateToken($token);

        if ($userId === null) {
            throw new \RuntimeException("Invalid or expired token.");
        }

        return $userId;
    }

    protected function validateToken(string $token): ?int {
        // Stub — Day 23 uses simple tokens stored in DB
        // Day 26+ would use JWT or OAuth
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT user_id FROM api_tokens
            WHERE token_hash = :hash AND expires_at > NOW()
        ");
        $stmt->execute([":hash" => hash("sha256", $token)]);
        $row = $stmt->fetch();
        return $row ? (int)$row["user_id"] : null;
    }
}
```

## Token authentication — simple API tokens

Sessions use cookies. APIs use tokens. A token is a long random string sent in every request header:

```sql
CREATE TABLE api_tokens (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    token_hash  VARCHAR(64)  NOT NULL UNIQUE,
    last_used   DATETIME     NULL,
    expires_at  DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```php
<?php
// src/Services/TokenService.php
declare(strict_types=1);

namespace App\Services;

use PDO;

class TokenService {
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $userId, string $name, int $daysValid = 365): string {
        $raw  = bin2hex(random_bytes(32));   // 64-char token
        $hash = hash("sha256", $raw);

        $this->pdo->prepare("
            INSERT INTO api_tokens (user_id, name, token_hash, expires_at)
            VALUES (:uid, :name, :hash, DATE_ADD(NOW(), INTERVAL :days DAY))
        ")->execute([
            ":uid"  => $userId,
            ":name" => $name,
            ":hash" => $hash,
            ":days" => $daysValid,
        ]);

        return $raw;   // return raw token — never stored, shown once
    }

    public function validate(string $raw): ?int {
        $hash = hash("sha256", $raw);
        $stmt = $this->pdo->prepare("
            SELECT user_id FROM api_tokens
            WHERE token_hash = :hash AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([":hash" => $hash]);
        $row = $stmt->fetch();

        if ($row === false) return null;

        // Update last_used — fire and forget
        $this->pdo->prepare("UPDATE api_tokens SET last_used = NOW() WHERE token_hash = :hash")
                  ->execute([":hash" => $hash]);

        return (int)$row["user_id"];
    }

    public function revoke(string $raw): void {
        $this->pdo->prepare("DELETE FROM api_tokens WHERE token_hash = :hash")
                  ->execute([":hash" => hash("sha256", $raw)]);
    }

    public function listForUser(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT id, name, last_used, expires_at, created_at
            FROM api_tokens WHERE user_id = :uid ORDER BY created_at DESC
        ");
        $stmt->execute([":uid" => $userId]);
        return $stmt->fetchAll();
    }
}
```

## The machines API controller

```php
<?php
// src/Http/Controllers/Api/MachineApiController.php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Services\FleetService;
use App\Services\TokenService;
use App\Exceptions\DeviceNotFoundException;

class MachineApiController extends ApiController {
    public function __construct(
        private readonly FleetService  $fleet,
        private readonly TokenService  $tokens,
    ) {}

    // GET /api/machines
    public function index(Request $request, array $params = []): Response {
        try {
            $userId = $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $status   = $request->get("status");
        $page     = max(1, (int)$request->get("page", 1));
        $perPage  = min(100, max(1, (int)$request->get("per_page", 20)));

        $machines = $status
            ? $this->fleet->getByStatus($status)
            : $this->fleet->getAll();

        // Paginate in-memory — in production do this in SQL
        $total   = count($machines);
        $sliced  = array_slice($machines, ($page - 1) * $perPage, $perPage);

        return $this->success(
            array_map(fn($m) => $m->toArray(), $sliced),
            200,
            [
                "total"    => $total,
                "page"     => $page,
                "per_page" => $perPage,
                "pages"    => (int)ceil($total / $perPage),
            ]
        );
    }

    // GET /api/machines/{id}
    public function show(Request $request, array $params = []): Response {
        try {
            $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        try {
            $machine = $this->fleet->getById($params["id"]);
            return $this->success($machine->toArray());
        } catch (DeviceNotFoundException $e) {
            return $this->notFound("Machine not found: {$e->getDeviceId()}");
        }
    }

    // POST /api/machines
    public function store(Request $request, array $params = []): Response {
        try {
            $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        try {
            $body = $this->jsonBody($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, "BAD_REQUEST");
        }

        // Validate
        $errors = [];

        $id = trim($body["id"] ?? "");
        if ($id === "") {
            $errors["id"] = "ID is required.";
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,18}[a-z0-9]$/', $id)) {
            $errors["id"] = "ID must be 3–20 chars: lowercase, numbers, hyphens.";
        }

        $location = trim($body["location"] ?? "");
        if ($location === "") {
            $errors["location"] = "Location is required.";
        }

        $status = $body["status"] ?? "offline";
        if (!in_array($status, ["online", "offline", "fault"], true)) {
            $errors["status"] = "Status must be online, offline, or fault.";
        }

        $slots = filter_var($body["slots"] ?? 20, FILTER_VALIDATE_INT,
                            ["options" => ["min_range" => 1, "max_range" => 200]]);
        if ($slots === false) {
            $errors["slots"] = "Slots must be an integer between 1 and 200.";
        }

        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        try {
            $machine = $this->fleet->register($id, $location, $status, (int)$slots);
            return $this->created($machine->toArray());
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), "Duplicate")) {
                return $this->error("Machine ID already exists.", 409, "CONFLICT");
            }
            return $this->error("Failed to create machine.", 500, "SERVER_ERROR");
        }
    }

    // PATCH /api/machines/{id}
    public function update(Request $request, array $params = []): Response {
        try {
            $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        try {
            $machine = $this->fleet->getById($params["id"]);
        } catch (DeviceNotFoundException $e) {
            return $this->notFound("Machine not found: {$e->getDeviceId()}");
        }

        try {
            $body = $this->jsonBody($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, "BAD_REQUEST");
        }

        $errors = [];

        // PATCH = partial update — only update fields that were sent
        if (array_key_exists("location", $body)) {
            $location = trim($body["location"]);
            if ($location === "") {
                $errors["location"] = "Location cannot be empty.";
            } else {
                $machine->location = $location;
            }
        }

        if (array_key_exists("status", $body)) {
            try {
                $machine->setStatus($body["status"]);
            } catch (\InvalidArgumentException $e) {
                $errors["status"] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            return $this->validationError($errors);
        }

        $this->fleet->save($machine);
        return $this->success($machine->toArray());
    }

    // DELETE /api/machines/{id}
    public function destroy(Request $request, array $params = []): Response {
        try {
            $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        try {
            $this->fleet->delete($params["id"]);
            return $this->noContent();
        } catch (DeviceNotFoundException $e) {
            return $this->notFound("Machine not found: {$e->getDeviceId()}");
        }
    }
}
```

## Registering API routes

```php
<?php
// In Application::registerRoutes()

// Add PUT and PATCH to the Router first
public function put(string $uri, string $controller, string $method): void {
    $this->addRoute("PUT", $uri, $controller, $method);
}

public function patch(string $uri, string $controller, string $method): void {
    $this->addRoute("PATCH", $uri, $controller, $method);
}

public function delete(string $uri, string $controller, string $method): void {
    $this->addRoute("DELETE", $uri, $controller, $method);
}

// API routes — all under /api prefix
$this->router->get("/api/machines",            MachineApiController::class, "index");
$this->router->post("/api/machines",           MachineApiController::class, "store");
$this->router->get("/api/machines/{id}",       MachineApiController::class, "show");
$this->router->patch("/api/machines/{id}",     MachineApiController::class, "update");
$this->router->delete("/api/machines/{id}",    MachineApiController::class, "destroy");

// Token management
$this->router->post("/api/tokens",             TokenApiController::class, "create");
$this->router->delete("/api/tokens/{id}",      TokenApiController::class, "revoke");
```

## Testing with curl

```bash
# First create a token — from the CLI or a one-off script
php -r "
require 'vendor/autoload.php';
\$pdo = new PDO('mysql:host=127.0.0.1;dbname=fleet_db', 'fleet_user', 'strongpassword');
\$svc = new App\Services\TokenService(\$pdo);
echo \$svc->create(1, 'dev-token') . PHP_EOL;
"

# Save the token
TOKEN="your_token_here"

# GET all machines
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/machines | json_pp

# GET with filter
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/machines?status=online" | json_pp

# POST — create a machine
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":"vend-010","location":"Rooftop","status":"offline","slots":15}' \
  http://localhost:8000/api/machines | json_pp

# PATCH — partial update
curl -s -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"online"}' \
  http://localhost:8000/api/machines/vend-010 | json_pp

# DELETE
curl -s -X DELETE \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/machines/vend-010

# Test 401 — no token
curl -s http://localhost:8000/api/machines | json_pp

# Test 422 — validation failure
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":"","location":""}' \
  http://localhost:8000/api/machines | json_pp

# Test 404 — machine not found
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/machines/does-not-exist | json_pp
```

## A token management controller

```php
<?php
// src/Http/Controllers/Api/TokenApiController.php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Services\TokenService;
use App\Services\AuthService;

class TokenApiController extends ApiController {
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuthService  $auth,
    ) {}

    // POST /api/tokens — exchange credentials for a token
    public function create(Request $request, array $params = []): Response {
        try {
            $body = $this->jsonBody($request);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, "BAD_REQUEST");
        }

        $email    = trim($body["email"]    ?? "");
        $password =      $body["password"] ?? "";
        $name     = trim($body["name"]     ?? "api-token");

        if ($email === "" || $password === "") {
            return $this->validationError([
                "email"    => $email    === "" ? "Email is required."    : null,
                "password" => $password === "" ? "Password is required." : null,
            ]);
        }

        $user = $this->auth->attempt($email, $password);

        if ($user === null) {
            return $this->error(
                "Invalid email or password.",
                401,
                "INVALID_CREDENTIALS"
            );
        }

        $token = $this->tokens->create($user["id"], $name);

        return $this->created([
            "token"      => $token,
            "token_type" => "Bearer",
            "expires_in" => 365 * 24 * 60 * 60,   // seconds
            "note"       => "Store this token securely. It will not be shown again.",
        ]);
    }

    // GET /api/tokens — list current user's tokens
    public function index(Request $request, array $params = []): Response {
        try {
            $userId = $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        $tokens = $this->tokens->listForUser($userId);

        // Never return token hashes — just metadata
        return $this->success(array_map(fn($t) => [
            "id"         => $t["id"],
            "name"       => $t["name"],
            "last_used"  => $t["last_used"],
            "expires_at" => $t["expires_at"],
            "created_at" => $t["created_at"],
        ], $tokens));
    }

    // DELETE /api/tokens/{id} — revoke a token
    public function revoke(Request $request, array $params = []): Response {
        try {
            $userId = $this->authenticate($request);
        } catch (\RuntimeException $e) {
            return $this->unauthorized();
        }

        // Verify the token belongs to the authenticated user
        $stmt = $this->pdo->prepare(
            "DELETE FROM api_tokens WHERE id = :id AND user_id = :uid"
        );
        $stmt->execute([":id" => $params["id"], ":uid" => $userId]);

        if ($stmt->rowCount() === 0) {
            return $this->notFound("Token not found.");
        }

        return $this->noContent();
    }
}
```

## Global exception handler for the API

Unhandled exceptions in API routes should return JSON, not HTML:

```php
<?php
// In Application::run() — wrap the dispatch in try/catch

public function run(): void {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header_remove("X-Powered-By");

    try {
        $match = $this->router->dispatch($this->request);

        if (empty($match)) {
            $this->notFoundResponse()->send();
            return;
        }

        $response = $this->resolveAndCall(
            $match["controller"],
            $match["method"],
            $match["params"]
        );

        $response->send();

    } catch (\Throwable $e) {
        error_log($e->getMessage() . "\n" . $e->getTraceAsString());

        // Is this an API request? Return JSON error
        if (str_starts_with($this->request->uri(), "/api/")) {
            (new Response())->json([
                "error" => [
                    "code"    => "SERVER_ERROR",
                    "message" => "An unexpected error occurred.",
                ],
                "meta" => ["timestamp" => date(\DateTimeInterface::ATOM)],
            ], 500)->send();
        } else {
            // Web request — return HTML error
            $env = $_ENV["APP_ENV"] ?? "production";
            if ($env === "development") {
                echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n"
                             . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            } else {
                (new Response())->html("<h1>500 — Server Error</h1>", 500)->send();
            }
        }
    }
}
```

---

## Today's exercise

![[Pasted image 20260603100809.png]]
The `GET /api/status` endpoint in Part B connects directly to your PDF's Cockpit architecture — the web dashboard needs to know which VMs are online, their status, and whether the broker is reachable. That endpoint is exactly what the PHP dashboard would poll. The inventory nested resource (`/api/machines/{id}/inventory`) models the "virtual boxes" from your concept doc — each slot in a vending machine is an inventory row.

The stretch goal's CORS handling is not optional if you ever want a browser-based JavaScript dashboard to call this API. Browsers enforce the same-origin policy — your frontend at `http://localhost:3000` cannot call your API at `http://localhost:8000` without the right CORS headers. Add it now and you'll never be confused by the "blocked by CORS policy" error again.

Paste your code when ready. Day 24 is consuming external APIs with Guzzle — the flip side of building APIs.