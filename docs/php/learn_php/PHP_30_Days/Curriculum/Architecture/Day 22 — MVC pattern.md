

You've built a working application. You've learned OOP, interfaces, traits, Composer, and the repository pattern. Today you organise all of it into MVC — the architectural pattern that every PHP framework uses. You're building it by hand so that when you use Laravel on Day 29 it feels like a shortcut, not a mystery.

## The mental model

```
Request → Router → Controller → Model/Service → View → Response

Router:     maps URLs to controller methods
Controller: receives request, calls services, passes data to view
Model:      represents data and business rules (your Day 18–20 classes)
Service:    orchestrates business logic across models
View:       renders HTML — knows nothing about databases or business rules
```

The key discipline: each layer talks only to the layer below it. Controllers don't write SQL. Views don't call services. Models don't know about HTTP. When a layer does only one thing, you can change it without touching the others.

## The folder structure

```
src/
  Http/
    Router.php           ← maps URIs to controller methods
    Request.php          ← wraps $_GET, $_POST, $_SERVER
    Response.php         ← wraps header(), http_response_code(), echo
    Controllers/
      BaseController.php
      HomeController.php
      MachineController.php
      AuthController.php
  Models/
    Machine.php
    Post.php
    User.php
  Repositories/
    MachineRepository.php
    PostRepository.php
  Services/
    FleetService.php
    PostService.php
  Views/
    layout.php
    home.php
    machines/
      index.php
      show.php
      form.php
  Exceptions/
    HttpException.php
    NotFoundException.php
  Application.php        ← wires everything together
public/
  index.php              ← single entry point
```

## The Request class

Wraps PHP's superglobals into a clean object:

```php
<?php
// src/Http/Request.php
declare(strict_types=1);

namespace App\Http;

class Request {
    private array $get;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private ?string $body = null;

    public function __construct() {
        $this->get     = $_GET    ?? [];
        $this->post    = $_POST   ?? [];
        $this->server  = $_SERVER ?? [];
        $this->files   = $_FILES  ?? [];
        $this->cookies = $_COOKIE ?? [];
    }

    public function method(): string {
        return strtoupper($this->server["REQUEST_METHOD"] ?? "GET");
    }

    public function uri(): string {
        return rtrim(strtok($this->server["REQUEST_URI"] ?? "/", "?"), "/") ?: "/";
    }

    public function isMethod(string $method): bool {
        return $this->method() === strtoupper($method);
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed {
        return $this->post[$key] ?? $default;
    }

    public function all(): array {
        return array_merge($this->get, $this->post);
    }

    public function only(array $keys): array {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool {
        return isset($this->all()[$key]);
    }

    public function file(string $key): ?array {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed {
        return $this->cookies[$key] ?? $default;
    }

    public function header(string $name): ?string {
        $key = "HTTP_" . strtoupper(str_replace("-", "_", $name));
        return $this->server[$key] ?? null;
    }

    public function ip(): string {
        return $this->server["REMOTE_ADDR"] ?? "0.0.0.0";
    }

    public function isAjax(): bool {
        return $this->header("X-Requested-With") === "XMLHttpRequest";
    }

    public function wantsJson(): bool {
        return str_contains($this->header("Accept") ?? "", "application/json");
    }

    public function body(): string {
        if ($this->body === null) {
            $this->body = file_get_contents("php://input") ?: "";
        }
        return $this->body;
    }

    public function json(): array {
        return json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
```

## The Response class

```php
<?php
// src/Http/Response.php
declare(strict_types=1);

namespace App\Http;

class Response {
    private int    $statusCode = 200;
    private array  $headers    = [];
    private string $body       = "";

    public function status(int $code): static {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): static {
        $this->headers[$name] = $value;
        return $this;
    }

    public function body(string $content): static {
        $this->body = $content;
        return $this;
    }

    public function json(mixed $data, int $status = 200): static {
        $this->statusCode = $status;
        $this->headers["Content-Type"] = "application/json";
        $this->body = json_encode($data, JSON_THROW_ON_ERROR);
        return $this;
    }

    public function redirect(string $url, int $status = 302): static {
        $this->statusCode = $status;
        $this->headers["Location"] = $url;
        return $this;
    }

    public function html(string $content, int $status = 200): static {
        $this->statusCode = $status;
        $this->headers["Content-Type"] = "text/html; charset=UTF-8";
        $this->body = $content;
        return $this;
    }

    public function send(): void {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        echo $this->body;
    }

    // Static factory methods — common responses
    public static function notFound(string $message = "Not found"): static {
        return (new static())->html("<h1>404 — $message</h1>", 404);
    }

    public static function forbidden(string $message = "Forbidden"): static {
        return (new static())->html("<h1>403 — $message</h1>", 403);
    }

    public static function serverError(string $message = "Server error"): static {
        return (new static())->html("<h1>500 — $message</h1>", 500);
    }
}
```

## The Router

```php
<?php
// src/Http/Router.php
declare(strict_types=1);

namespace App\Http;

class Router {
    private array $routes = [];

    public function get(string $uri, string $controller, string $method): void {
        $this->addRoute("GET", $uri, $controller, $method);
    }

    public function post(string $uri, string $controller, string $method): void {
        $this->addRoute("POST", $uri, $controller, $method);
    }

    private function addRoute(
        string $httpMethod,
        string $uri,
        string $controller,
        string $method
    ): void {
        $this->routes[] = [
            "http_method" => $httpMethod,
            "uri"         => $uri,
            "controller"  => $controller,
            "method"      => $method,
            "pattern"     => $this->uriToPattern($uri),
        ];
    }

    private function uriToPattern(string $uri): string {
        // Convert /machines/{id} to a regex pattern
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $uri);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(Request $request): array {
        $method = $request->method();
        $uri    = $request->uri();

        foreach ($this->routes as $route) {
            if ($route["http_method"] !== $method) {
                continue;
            }

            if (preg_match($route["pattern"], $uri, $matches)) {
                // Extract named capture groups as route params
                $params = array_filter(
                    $matches,
                    fn($k): bool => !is_int($k),
                    ARRAY_FILTER_USE_KEY
                );

                return [
                    "controller" => $route["controller"],
                    "method"     => $route["method"],
                    "params"     => $params,
                ];
            }
        }

        return [];   // no match
    }
}
```

This router supports URL parameters — `/machines/{id}` matches `/machines/vend-001` and gives you `$params["id"] = "vend-001"`. That's how Laravel-style routes work.

## The base controller

```php
<?php
// src/Http/Controllers/BaseController.php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Response;

abstract class BaseController {
    // Render a view — captures output and wraps in layout
    protected function view(
        string $template,
        array  $data    = [],
        int    $status  = 200
    ): Response {
        // Make data available as variables in the template
        extract($data);

        // Helper always available in views
        $e = fn(string $v): string =>
            htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, "UTF-8");

        ob_start();
        require __DIR__ . "/../../Views/$template.php";
        $content = ob_get_clean();

        ob_start();
        require __DIR__ . "/../../Views/layout.php";
        $html = ob_get_clean();

        return (new Response())->html($html, $status);
    }

    protected function json(mixed $data, int $status = 200): Response {
        return (new Response())->json($data, $status);
    }

    protected function redirect(string $url, int $status = 302): Response {
        return (new Response())->redirect($url, $status);
    }

    protected function flash(string $type, string $message): void {
        $_SESSION["flash"][] = ["type" => $type, "message" => $message];
    }

    protected function csrfToken(): string {
        if (empty($_SESSION["csrf_token"])) {
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
        }
        return $_SESSION["csrf_token"];
    }

    protected function verifyCsrf(): void {
        $submitted = $_POST["csrf_token"] ?? "";
        $expected  = $_SESSION["csrf_token"] ?? "";
        if (!hash_equals($expected, $submitted)) {
            http_response_code(403);
            die("Invalid CSRF token.");
        }
    }

    protected function requireLogin(): void {
        if (empty($_SESSION["logged_in"])) {
            $_SESSION["intended_url"] = $_SERVER["REQUEST_URI"];
            header("Location: /login");
            exit;
        }
    }

    protected function currentUser(): ?array {
        if (empty($_SESSION["logged_in"])) return null;
        return [
            "id"       => $_SESSION["user_id"],
            "username" => $_SESSION["username"],
        ];
    }
}
```

## The MachineController

```php
<?php
// src/Http/Controllers/MachineController.php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\FleetService;
use App\Exceptions\DeviceNotFoundException;

class MachineController extends BaseController {
    public function __construct(
        private readonly FleetService $fleet,
    ) {}

    // GET /machines
    public function index(Request $request, array $params = []): Response {
        $this->requireLogin();

        $status   = $request->get("status");
        $machines = $status
            ? $this->fleet->getByStatus($status)
            : $this->fleet->getAll();

        return $this->view("machines/index", [
            "machines"      => $machines,
            "activeStatus"  => $status,
            "title"         => "Machines",
            "csrf"          => $this->csrfToken(),
        ]);
    }

    // GET /machines/{id}
    public function show(Request $request, array $params = []): Response {
        $this->requireLogin();

        try {
            $machine = $this->fleet->getById($params["id"]);
        } catch (DeviceNotFoundException $e) {
            return Response::notFound("Machine not found: " . $e->getDeviceId());
        }

        return $this->view("machines/show", [
            "machine" => $machine,
            "title"   => $machine->id,
        ]);
    }

    // GET /machines/create
    public function create(Request $request, array $params = []): Response {
        $this->requireLogin();

        return $this->view("machines/form", [
            "machine" => null,
            "errors"  => [],
            "values"  => ["id" => "", "location" => "", "status" => "offline", "slots" => 20],
            "title"   => "Register Machine",
            "csrf"    => $this->csrfToken(),
        ]);
    }

    // POST /machines
    public function store(Request $request, array $params = []): Response {
        $this->requireLogin();
        $this->verifyCsrf();

        $values = $request->only(["id", "location", "status", "slots"]);
        $errors = $this->validate($values);

        if (!empty($errors)) {
            return $this->view("machines/form", [
                "machine" => null,
                "errors"  => $errors,
                "values"  => $values,
                "title"   => "Register Machine",
                "csrf"    => $this->csrfToken(),
            ], 422);
        }

        try {
            $machine = $this->fleet->register(
                $values["id"],
                $values["location"],
                $values["status"],
                (int)$values["slots"],
            );

            $this->flash("success", "Machine '{$machine->id}' registered.");
            return $this->redirect("/machines");

        } catch (\RuntimeException $e) {
            $errors["_db"] = $e->getMessage();
            return $this->view("machines/form", [
                "machine" => null,
                "errors"  => $errors,
                "values"  => $values,
                "title"   => "Register Machine",
                "csrf"    => $this->csrfToken(),
            ], 422);
        }
    }

    // GET /machines/{id}/edit
    public function edit(Request $request, array $params = []): Response {
        $this->requireLogin();

        try {
            $machine = $this->fleet->getById($params["id"]);
        } catch (DeviceNotFoundException $e) {
            return Response::notFound("Machine not found.");
        }

        return $this->view("machines/form", [
            "machine" => $machine,
            "errors"  => [],
            "values"  => [
                "id"       => $machine->id,
                "location" => $machine->location,
                "status"   => $machine->getStatus(),
                "slots"    => 20,
            ],
            "title"   => "Edit {$machine->id}",
            "csrf"    => $this->csrfToken(),
        ]);
    }

    // POST /machines/{id}
    public function update(Request $request, array $params = []): Response {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            $machine = $this->fleet->getById($params["id"]);
        } catch (DeviceNotFoundException $e) {
            return Response::notFound("Machine not found.");
        }

        $values = $request->only(["location", "status"]);
        $errors = [];

        if (empty(trim($values["location"] ?? ""))) {
            $errors["location"] = "Location is required.";
        }

        if (empty($errors)) {
            $machine->location = $values["location"];
            $machine->setStatus($values["status"]);
            $this->fleet->save($machine);

            $this->flash("success", "Machine '{$machine->id}' updated.");
            return $this->redirect("/machines");
        }

        return $this->view("machines/form", [
            "machine" => $machine,
            "errors"  => $errors,
            "values"  => array_merge(["id" => $machine->id], $values),
            "title"   => "Edit {$machine->id}",
            "csrf"    => $this->csrfToken(),
        ], 422);
    }

    // POST /machines/{id}/delete
    public function destroy(Request $request, array $params = []): Response {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            $this->fleet->delete($params["id"]);
            $this->flash("success", "Machine deleted.");
        } catch (DeviceNotFoundException $e) {
            $this->flash("error", "Machine not found.");
        }

        return $this->redirect("/machines");
    }

    private function validate(array $values): array {
        $errors = [];

        if (empty(trim($values["id"] ?? ""))) {
            $errors["id"] = "ID is required.";
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,18}[a-z0-9]$/', $values["id"])) {
            $errors["id"] = "ID must be 3–20 chars: lowercase, numbers, hyphens.";
        }

        if (empty(trim($values["location"] ?? ""))) {
            $errors["location"] = "Location is required.";
        }

        if (!in_array($values["status"] ?? "", ["online", "offline", "fault"], true)) {
            $errors["status"] = "Invalid status.";
        }

        $slots = filter_var($values["slots"] ?? "", FILTER_VALIDATE_INT,
                            ["options" => ["min_range" => 1, "max_range" => 200]]);
        if ($slots === false) {
            $errors["slots"] = "Slots must be between 1 and 200.";
        }

        return $errors;
    }
}
```

## The Application — wiring it together

```php
<?php
// src/Application.php
declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Router;
use App\Http\Response;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\AuthController;
use App\Services\FleetService;
use App\Services\AuthService;
use App\Repositories\MachineRepository;
use App\Repositories\UserRepository;
use PDO;

class Application {
    private Router  $router;
    private Request $request;
    private PDO     $pdo;

    public function __construct() {
        $this->request = new Request();
        $this->router  = new Router();
        $this->pdo     = $this->buildPdo();

        $this->registerRoutes();
    }

    private function buildPdo(): PDO {
        return new PDO(
            sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4",
                $_ENV["DB_HOST"], $_ENV["DB_NAME"]),
            $_ENV["DB_USER"],
            $_ENV["DB_PASS"],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    private function registerRoutes(): void {
        // Home
        $this->router->get("/", HomeController::class, "index");

        // Auth
        $this->router->get("/login",    AuthController::class, "loginForm");
        $this->router->post("/login",   AuthController::class, "login");
        $this->router->get("/register", AuthController::class, "registerForm");
        $this->router->post("/register",AuthController::class, "register");
        $this->router->post("/logout",  AuthController::class, "logout");

        // Machines
        $this->router->get("/machines",              MachineController::class, "index");
        $this->router->get("/machines/create",       MachineController::class, "create");
        $this->router->post("/machines",             MachineController::class, "store");
        $this->router->get("/machines/{id}",         MachineController::class, "show");
        $this->router->get("/machines/{id}/edit",    MachineController::class, "edit");
        $this->router->post("/machines/{id}",        MachineController::class, "update");
        $this->router->post("/machines/{id}/delete", MachineController::class, "destroy");
    }

    public function run(): void {
        // Apply security headers
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header_remove("X-Powered-By");

        $match = $this->router->dispatch($this->request);

        if (empty($match)) {
            Response::notFound()->send();
            return;
        }

        // Build the controller with its dependencies injected
        $response = $this->resolveAndCall(
            $match["controller"],
            $match["method"],
            $match["params"]
        );

        $response->send();
    }

    private function resolveAndCall(
        string $controllerClass,
        string $method,
        array  $params
    ): Response {
        // Manual dependency injection — resolve what each controller needs
        $controller = match($controllerClass) {
            MachineController::class => new MachineController(
                new FleetService(new MachineRepository($this->pdo))
            ),
            AuthController::class => new AuthController(
                new AuthService(new UserRepository($this->pdo))
            ),
            HomeController::class => new HomeController(),
            default               => throw new \RuntimeException(
                "Unknown controller: $controllerClass"
            ),
        };

        return $controller->$method($this->request, $params);
    }
}
```

## The entry point

```php
<?php
// public/index.php
declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

use Dotenv\Dotenv;
use App\Application;

// Load environment
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
$dotenv->required(["DB_HOST", "DB_NAME", "DB_USER", "DB_PASS"]);

// Start session
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
session_start();

// Run
(new Application())->run();
```

## A view — machines/index.php

```php
<?php
// src/Views/machines/index.php
// Variables available: $machines, $activeStatus, $csrf, $e (escaper fn)
declare(strict_types=1);
?>
<div style="display:flex;justify-content:space-between;align-items:center">
    <h1>Machines</h1>
    <a href="/machines/create">+ Register Machine</a>
</div>

<form method="GET" action="/machines" style="margin:1rem 0">
    <select name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (["online","offline","fault"] as $s): ?>
            <option value="<?= $e($s) ?>"
                <?= $activeStatus === $s ? "selected" : "" ?>>
                <?= $e(ucfirst($s)) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (empty($machines)): ?>
    <p>No machines found.</p>
<?php else: ?>
    <table style="width:100%;border-collapse:collapse">
        <thead>
            <tr>
                <th>ID</th><th>Location</th>
                <th>Status</th><th>Fill</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($machines as $m): ?>
            <tr>
                <td><a href="/machines/<?= $e($m->id) ?>"><?= $e($m->id) ?></a></td>
                <td><?= $e($m->location) ?></td>
                <td><?= $e($m->getStatus()) ?></td>
                <td><?= $e((string)$m->fillPercent()) ?>%</td>
                <td>
                    <a href="/machines/<?= $e($m->id) ?>/edit">Edit</a>
                    <form method="POST"
                          action="/machines/<?= $e($m->id) ?>/delete"
                          style="display:inline"
                          onsubmit="return confirm('Delete <?= $e($m->id) ?>?')">
                        <input type="hidden" name="csrf_token"
                               value="<?= $e($csrf) ?>">
                        <button type="submit" class="btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
```

---

## Today's exercise

![[Pasted image 20260603100514.png]]
Day 22 is the most architecturally significant day of the course. When you finish, look at your `MachineController` — it has no SQL, no `$_POST` reads, no `htmlspecialchars` calls, no session logic. It only calls services and returns responses. That separation is what makes large codebases maintainable.

The stretch goal's middleware concept is worth building even if it takes extra time. Moving `requireLogin()` from every controller method into the router is the difference between checking a lock at every door versus checking it at the building entrance. Every framework implements this — Laravel calls them middleware, Slim calls them route guards. Build it once from scratch and you'll understand every framework's version immediately.

Paste your code when ready. Day 23 is REST APIs — your MVC foundation makes the API layer almost trivial to add.