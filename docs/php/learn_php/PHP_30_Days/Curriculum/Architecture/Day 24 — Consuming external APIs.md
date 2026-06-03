
You've built an API. Today you consume one. Every real application talks to external services — weather data, payment processors, SMS gateways, MQTT brokers via HTTP, IoT cloud platforms. The skills are the same regardless of which API you're calling.

## The mental model — your app as a client

```
Day 23: Browser/curl → your PHP API → your database
Today:  Your PHP app → external API → their data

Your PHP is now the client.
The external service is the server.
You control the request. You don't control the response.
```

Everything that can go wrong will, eventually: timeouts, rate limits, malformed responses, auth failures, service outages. Robust API consumption means handling all of these gracefully.

## cURL vs Guzzle — why Guzzle

PHP's built-in cURL works but is verbose and error-prone:

```php
<?php
declare(strict_types=1);

// Raw cURL — functional but painful
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.example.com/data");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer token123"]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    throw new \RuntimeException("cURL error: $error");
}
$data = json_decode($response, true);
```

Guzzle wraps cURL in a clean object-oriented interface with middleware, retries, async support, and automatic error handling:

```bash
composer require guzzlehttp/guzzle
```

```php
<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

// Create a client — configure once, reuse everywhere
$client = new Client([
    "base_uri" => "https://api.example.com/",
    "timeout"  => 10.0,                // total request timeout in seconds
    "connect_timeout" => 5.0,          // connection establishment timeout
    "headers"  => [
        "Authorization" => "Bearer " . $_ENV["API_KEY"],
        "Accept"        => "application/json",
        "User-Agent"    => "fleet-manager/1.0",
    ],
]);
```

## GET requests

```php
<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$client = new Client(["base_uri" => "https://api.open-meteo.com/", "timeout" => 10.0]);

try {
    $response = $client->get("v1/forecast", [
        "query" => [
            "latitude"       => 45.75,   // Galați approximate lat
            "longitude"      => 27.93,
            "current_weather"=> true,
            "hourly"         => "temperature_2m,wind_speed_10m",
        ],
    ]);

    // Status code
    $status = $response->getStatusCode();           // 200
    echo "Status: $status\n";

    // Response headers
    $contentType = $response->getHeaderLine("Content-Type");
    echo "Content-Type: $contentType\n";

    // Body — always decode, never trust client-supplied encoding
    $body = (string)$response->getBody();
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    $current = $data["current_weather"];
    echo "Temperature: {$current['temperature']}°C\n";
    echo "Wind speed:  {$current['windspeed']} km/h\n";

} catch (\JsonException $e) {
    throw new \RuntimeException("Invalid JSON response: " . $e->getMessage(), 0, $e);
} catch (GuzzleException $e) {
    throw new \RuntimeException("API request failed: " . $e->getMessage(), 0, $e);
}
```

## POST requests — sending JSON

```php
<?php
declare(strict_types=1);

use GuzzleHttp\Client;

$client = new Client([
    "base_uri" => "https://api.example.com/",
    "timeout"  => 15.0,
    "headers"  => [
        "Authorization" => "Bearer " . $_ENV["EXAMPLE_API_KEY"],
        "Content-Type"  => "application/json",
        "Accept"        => "application/json",
    ],
]);

// POST with JSON body — Guzzle sets Content-Type automatically
$response = $client->post("v1/devices", [
    "json" => [
        "id"       => "vend-001",
        "location" => "Floor 1",
        "type"     => "vending_machine",
        "tags"     => ["production", "floor-1"],
    ],
]);

$created = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
echo "Created device: {$created['data']['id']}\n";

// POST with form data (application/x-www-form-urlencoded)
$response = $client->post("v1/auth/token", [
    "form_params" => [
        "grant_type"    => "client_credentials",
        "client_id"     => $_ENV["CLIENT_ID"],
        "client_secret" => $_ENV["CLIENT_SECRET"],
    ],
]);
```

## Exception handling — Guzzle's hierarchy

```php
<?php
declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\RequestException;

try {
    $response = $client->get("v1/machines");
    $data     = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

} catch (ConnectException $e) {
    // Network error — DNS failure, refused connection, timeout
    // No HTTP response available
    error_log("Network error: " . $e->getMessage());
    throw new \RuntimeException("Could not reach API server.", 0, $e);

} catch (ClientException $e) {
    // 4xx — our request was wrong
    $status   = $e->getResponse()->getStatusCode();
    $body     = (string)$e->getResponse()->getBody();

    $decoded = json_decode($body, true);
    $message = $decoded["error"]["message"] ?? "Client error";

    throw match($status) {
        401 => new \RuntimeException("API authentication failed: $message"),
        403 => new \RuntimeException("API access denied: $message"),
        404 => new \RuntimeException("Resource not found: $message"),
        422 => new \RuntimeException("Validation failed: $message"),
        429 => new \RuntimeException("Rate limited — slow down requests"),
        default => new \RuntimeException("API client error $status: $message"),
    };

} catch (ServerException $e) {
    // 5xx — their server failed
    $status = $e->getResponse()->getStatusCode();
    error_log("API server error $status: " . $e->getMessage());
    throw new \RuntimeException("API server error — try again later.", 0, $e);

} catch (\JsonException $e) {
    throw new \RuntimeException("Invalid API response format.", 0, $e);
}
```

The exception hierarchy matters: `ConnectException` means you never got a response. `ClientException` (4xx) means the server responded but rejected your request. `ServerException` (5xx) means the server responded but failed on its end. Each needs different handling.

## Building a proper API client class

Don't scatter Guzzle calls throughout your codebase. Wrap each external API in a dedicated client class:

```php
<?php
// src/Http/Clients/WeatherClient.php
declare(strict_types=1);

namespace App\Http\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

class WeatherClient {
    private Client $http;

    public function __construct(
        private readonly string $baseUri = "https://api.open-meteo.com/",
        private readonly float  $timeout = 10.0,
    ) {
        $this->http = new Client([
            "base_uri" => $this->baseUri,
            "timeout"  => $this->timeout,
            "headers"  => [
                "Accept"     => "application/json",
                "User-Agent" => "fleet-manager/1.0",
            ],
        ]);
    }

    public function current(float $lat, float $lon): array {
        try {
            $response = $this->http->get("v1/forecast", [
                "query" => [
                    "latitude"        => $lat,
                    "longitude"       => $lon,
                    "current_weather" => true,
                ],
            ]);

            $data = $this->decode($response);
            return $data["current_weather"];

        } catch (ConnectException $e) {
            throw new \RuntimeException("Weather API unreachable.", 0, $e);
        } catch (ClientException $e) {
            throw new \RuntimeException("Weather API request invalid: " .
                $e->getResponse()->getStatusCode(), 0, $e);
        } catch (ServerException $e) {
            throw new \RuntimeException("Weather API server error — try later.", 0, $e);
        }
    }

    public function forecast(float $lat, float $lon, int $days = 7): array {
        try {
            $response = $this->http->get("v1/forecast", [
                "query" => [
                    "latitude"        => $lat,
                    "longitude"       => $lon,
                    "hourly"          => "temperature_2m,precipitation,wind_speed_10m",
                    "forecast_days"   => $days,
                ],
            ]);

            return $this->decode($response);

        } catch (ConnectException $e) {
            throw new \RuntimeException("Weather API unreachable.", 0, $e);
        } catch (ClientException | ServerException $e) {
            throw new \RuntimeException("Weather API error.", 0, $e);
        }
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array {
        try {
            return json_decode(
                (string)$response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON from Weather API.", 0, $e);
        }
    }
}
```

```php
<?php
// src/Http/Clients/MqttBrokerClient.php
declare(strict_types=1);

namespace App\Http\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;

// EMQX broker has an HTTP management API — perfect for your project
class MqttBrokerClient {
    private Client $http;

    public function __construct(
        string $host     = "localhost",
        int    $port     = 18083,
        string $username = "admin",
        string $password = "public",
        float  $timeout  = 5.0,
    ) {
        $this->http = new Client([
            "base_uri" => "http://{$host}:{$port}/api/v5/",
            "timeout"  => $timeout,
            "auth"     => [$username, $password],
            "headers"  => [
                "Accept"       => "application/json",
                "Content-Type" => "application/json",
            ],
        ]);
    }

    // Get all connected clients
    public function getClients(): array {
        try {
            $response = $this->http->get("clients");
            $data     = $this->decode($response);
            return $data["data"] ?? [];
        } catch (ConnectException $e) {
            throw new \RuntimeException("MQTT broker unreachable.", 0, $e);
        }
    }

    // Check if a specific device is connected
    public function isClientConnected(string $clientId): bool {
        try {
            $response = $this->http->get("clients/$clientId");
            return $response->getStatusCode() === 200;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            throw new \RuntimeException("Broker API error.", 0, $e);
        } catch (ConnectException $e) {
            throw new \RuntimeException("MQTT broker unreachable.", 0, $e);
        }
    }

    // Publish a message via HTTP API
    public function publish(
        string $topic,
        mixed  $payload,
        int    $qos    = 1,
        bool   $retain = false
    ): void {
        try {
            $this->http->post("publish", [
                "json" => [
                    "topic"   => $topic,
                    "payload" => is_array($payload)
                        ? json_encode($payload, JSON_THROW_ON_ERROR)
                        : (string)$payload,
                    "qos"     => $qos,
                    "retain"  => $retain,
                    "encoding"=> "plain",
                ],
            ]);
        } catch (ConnectException $e) {
            throw new \RuntimeException("Cannot reach MQTT broker.", 0, $e);
        } catch (ClientException $e) {
            throw new \RuntimeException(
                "Broker rejected publish: " . $e->getResponse()->getStatusCode(),
                0, $e
            );
        }
    }

    // Get broker statistics
    public function stats(): array {
        try {
            $response = $this->http->get("stats");
            return $this->decode($response);
        } catch (ConnectException $e) {
            throw new \RuntimeException("MQTT broker unreachable.", 0, $e);
        }
    }

    // Get all subscriptions for a topic pattern
    public function getSubscriptions(string $topic = "#"): array {
        try {
            $response = $this->http->get("subscriptions", [
                "query" => ["topic" => $topic],
            ]);
            $data = $this->decode($response);
            return $data["data"] ?? [];
        } catch (ConnectException $e) {
            throw new \RuntimeException("MQTT broker unreachable.", 0, $e);
        }
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array {
        try {
            return json_decode(
                (string)$response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON from broker API.", 0, $e);
        }
    }
}
```

The `MqttBrokerClient` is directly relevant to your project — EMQX (one of the most popular MQTT brokers) exposes an HTTP management API on port 18083. Your PHP dashboard can query it to see which devices are connected, publish commands, and monitor broker health — all without needing a native MQTT PHP library.

## Retry logic — handling transient failures

```php
<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function buildRetryClient(int $maxRetries = 3): Client {
    $stack = HandlerStack::create();

    // Add retry middleware
    $stack->push(Middleware::retry(
        // Decide whether to retry
        function(
            int                $retries,
            RequestInterface   $request,
            ?ResponseInterface $response,
            ?\Throwable        $exception
        ) use ($maxRetries): bool {
            // Don't retry beyond max
            if ($retries >= $maxRetries) return false;

            // Retry on connection errors
            if ($exception instanceof ConnectException) return true;

            // Retry on 5xx server errors
            if ($response && $response->getStatusCode() >= 500) return true;

            // Retry on 429 Too Many Requests
            if ($response && $response->getStatusCode() === 429) return true;

            return false;
        },
        // Delay between retries — exponential backoff
        function(int $retries, ?ResponseInterface $response): int {
            // If server sent Retry-After header, respect it
            if ($response && $response->hasHeader("Retry-After")) {
                return (int)$response->getHeaderLine("Retry-After") * 1000;
            }
            // Exponential backoff: 1s, 2s, 4s...
            return (int)(1000 * pow(2, $retries - 1));
        }
    ));

    return new Client([
        "handler"         => $stack,
        "timeout"         => 10.0,
        "connect_timeout" => 5.0,
    ]);
}

$client = buildRetryClient(3);
// Now all requests automatically retry on transient failures
```

## Caching API responses

External API calls cost time and sometimes money (rate limits, pay-per-call). Cache responses that don't change frequently:

```php
<?php
declare(strict_types=1);

namespace App\Http\Clients;

use App\Contracts\Cache;

class CachedWeatherClient {
    public function __construct(
        private readonly WeatherClient $client,
        private readonly Cache         $cache,
        private readonly int           $ttl = 600,  // 10 minutes
    ) {}

    public function current(float $lat, float $lon): array {
        $key = "weather:current:" . md5("$lat:$lon");

        return $this->cache->remember($key, function() use ($lat, $lon): array {
            return $this->client->current($lat, $lon);
        }, $this->ttl);
    }

    public function forecast(float $lat, float $lon, int $days = 7): array {
        $key = "weather:forecast:" . md5("$lat:$lon:$days");

        return $this->cache->remember($key, function() use ($lat, $lon, $days): array {
            return $this->client->forecast($lat, $lon, $days);
        }, $this->ttl);
    }
}
```

The `CachedWeatherClient` wraps `WeatherClient` transparently. Callers don't know or care whether the result came from cache or the live API. This is the decorator pattern — adding behaviour (caching) without modifying the original class.

## Integrating into your MVC application

```php
<?php
// src/Http/Controllers/Api/StatusApiController.php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Http\Clients\WeatherClient;
use App\Http\Clients\MqttBrokerClient;
use App\Services\FleetService;

class StatusApiController extends ApiController {
    public function __construct(
        private readonly FleetService    $fleet,
        private readonly WeatherClient   $weather,
        private readonly MqttBrokerClient $broker,
    ) {}

    // GET /api/status — dashboard status endpoint
    public function index(Request $request, array $params = []): Response {
        $machines = $this->fleet->getAll();
        $online   = array_filter($machines, fn($m) => $m->isOnline());
        $faults   = array_filter($machines, fn($m) => $m->getStatus() === "fault");

        // Weather — non-critical, fail gracefully
        $weather = null;
        try {
            $weather = $this->weather->current(45.75, 27.93);
        } catch (\RuntimeException $e) {
            error_log("Weather API failed: " . $e->getMessage());
        }

        // Broker status — non-critical, fail gracefully
        $brokerStats = null;
        try {
            $brokerStats = $this->broker->stats();
        } catch (\RuntimeException $e) {
            error_log("Broker API failed: " . $e->getMessage());
        }

        return $this->success([
            "fleet" => [
                "total"   => count($machines),
                "online"  => count($online),
                "faults"  => count($faults),
                "offline" => count($machines) - count($online) - count($faults),
            ],
            "weather" => $weather ? [
                "temperature" => $weather["temperature"],
                "windspeed"   => $weather["windspeed"],
            ] : null,
            "broker" => $brokerStats ? [
                "connections" => $brokerStats["connections.count"] ?? null,
                "messages_in" => $brokerStats["messages.received"] ?? null,
            ] : null,
            "server" => [
                "php_version" => PHP_VERSION,
                "uptime"      => $this->serverUptime(),
            ],
        ]);
    }

    private function serverUptime(): string {
        if (PHP_OS_FAMILY === "Linux") {
            $uptime = shell_exec("uptime -p") ?: "unknown";
            return trim($uptime);
        }
        return "unknown";
    }
}
```

Notice the weather and broker calls are wrapped in their own try/catch blocks. If either external service fails, the status endpoint still returns successfully with the fleet data — just without the weather or broker sections. Never let an optional third-party API failure crash your core endpoint.

## A practical currency/unit conversion client

```php
<?php
// src/Http/Clients/ExchangeRateClient.php
declare(strict_types=1);

namespace App\Http\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

// Uses exchangerate-api.com free tier (no key needed for basic)
class ExchangeRateClient {
    private Client $http;

    public function __construct(float $timeout = 8.0) {
        $this->http = new Client([
            "base_uri" => "https://open.er-api.com/",
            "timeout"  => $timeout,
            "headers"  => ["Accept" => "application/json"],
        ]);
    }

    public function getRate(string $from, string $to): float {
        try {
            $response = $this->http->get("v6/latest/$from");
            $data     = json_decode(
                (string)$response->getBody(),
                true, 512, JSON_THROW_ON_ERROR
            );

            $to = strtoupper($to);
            if (!isset($data["rates"][$to])) {
                throw new \RuntimeException("Currency not found: $to");
            }

            return (float)$data["rates"][$to];

        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                "Exchange rate API failed: " . $e->getMessage(), 0, $e
            );
        }
    }

    public function convert(float $amount, string $from, string $to): float {
        $rate = $this->getRate($from, $to);
        return round($amount * $rate, 4);
    }
}

// Usage
$fx  = new ExchangeRateClient();
$eur = $fx->convert(100.0, "USD", "EUR");
echo "100 USD = $eur EUR\n";
```

---

## Today's exercise

![[Pasted image 20260603101108.png]]


Part B's `GET /api/firmware/latest` is the PHP-side of your daemon's OTA service. In your C daemon, `ota_handle_update()` receives a version and URL from the MQTT broker. The broker gets that information from somewhere — in a real system, it's a backend service like this one that queries GitHub releases, packages the info as an MQTT message, and publishes it to the device topic. You've now built both ends of that pipeline.

The stretch goal's `X-Cache` header is a habit from production API work. Every caching proxy (Varnish, Cloudflare, Nginx) adds this header so you can diagnose cache behaviour without reading log files. Building it into your own clients teaches you to think about observability as you build, not as an afterthought.

Paste your code when ready. Day 25 is PHPUnit — testing the code you've built so far.