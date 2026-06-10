##### Talking to the Outside World

## What an API actually is

An API (Application Programming Interface) is a contract. A server exposes URLs — endpoints — and promises that if you send the right request, you'll get back data in a predictable format. Almost all modern APIs speak HTTP and return JSON.

```
You                          Server
  |                            |
  |-- GET /users/42 ---------->|
  |                            |-- finds user 42
  |<-- 200 OK + JSON body -----|
  |                            |
  |-- POST /users ------------>|  (with JSON body)
  |                            |-- creates user
  |<-- 201 Created + JSON -----|
```

HTTP methods have specific meanings:

- `GET` — retrieve data, no side effects
- `POST` — create something new
- `PUT` — replace something entirely
- `PATCH` — update specific fields
- `DELETE` — remove something

---

## The requests library — the standard for HTTP in Python

```bash
pip install requests
```

```python
import requests

# Basic GET request
response = requests.get("https://httpbin.org/get")

print(response.status_code)     # 200
print(response.headers)         # dict of response headers
print(response.text)            # raw response body as string
print(response.json())          # parse body as JSON — returns dict/list
print(response.url)             # final URL after redirects
print(response.elapsed)         # how long the request took
```

`response.json()` is the shortcut for `json.loads(response.text)`. Use it when you expect JSON back — which is almost always.

---

## Response status codes — what they mean

```python
response = requests.get("https://api.example.com/users/42")

# Check if successful before using the data
if response.status_code == 200:
    data = response.json()
elif response.status_code == 404:
    print("User not found")
elif response.status_code == 401:
    print("Unauthorized — check your API key")
elif response.status_code == 429:
    print("Rate limited — slow down")
elif response.status_code >= 500:
    print("Server error — not your fault")

# Better — raise an exception for any 4xx or 5xx
response.raise_for_status()    # raises requests.HTTPError if status >= 400
data = response.json()         # only runs if status was 2xx
```

**Status code families:**

- `2xx` — success (200 OK, 201 Created, 204 No Content)
- `3xx` — redirect (requests follows these automatically)
- `4xx` — your fault (400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found, 429 Too Many Requests)
- `5xx` — server's fault (500 Internal Server Error, 503 Service Unavailable)

---

## Query parameters, headers, and request body

```python
import requests

# Query parameters — appended to URL as ?key=value&key2=value2
response = requests.get(
    "https://api.github.com/search/repositories",
    params={"q": "python", "sort": "stars", "per_page": 5}
)
# Requests builds: /search/repositories?q=python&sort=stars&per_page=5
print(response.url)

# Headers — authentication, content type, custom headers
response = requests.get(
    "https://api.github.com/user",
    headers={
        "Authorization": "token YOUR_TOKEN_HERE",
        "Accept": "application/vnd.github.v3+json",
        "User-Agent": "MyApp/1.0"
    }
)

# POST with JSON body
response = requests.post(
    "https://httpbin.org/post",
    json={"name": "Alice", "age": 30}    # automatically sets Content-Type: application/json
)

# POST with form data
response = requests.post(
    "https://httpbin.org/post",
    data={"username": "alice", "password": "secret"}    # form encoding
)

# PUT — replace a resource
response = requests.put(
    "https://httpbin.org/put",
    json={"id": 42, "name": "Alice Updated"}
)

# DELETE
response = requests.delete("https://httpbin.org/delete")
```

---

## Sessions — reusing connections and credentials

```python
import requests

# Without session — creates a new connection for every request
requests.get("https://api.example.com/users")
requests.get("https://api.example.com/posts")

# With session — reuses TCP connection, shares headers/auth
session = requests.Session()
session.headers.update({
    "Authorization": "Bearer YOUR_TOKEN",
    "User-Agent": "MyApp/1.0"
})

# All requests through this session share the headers
response = session.get("https://api.example.com/users")
response = session.get("https://api.example.com/posts")
session.close()

# Use as context manager — auto-closes
with requests.Session() as session:
    session.headers.update({"Authorization": "Bearer YOUR_TOKEN"})
    users = session.get("https://api.example.com/users").json()
    posts = session.get("https://api.example.com/posts").json()
```

Use a session whenever you're making multiple requests to the same API. It's faster (connection reuse) and cleaner (shared configuration).

---

## Error handling — writing requests code that doesn't crash

```python
import requests
from requests.exceptions import (
    HTTPError,
    ConnectionError,
    Timeout,
    RequestException
)

def get_user(user_id, timeout=5):
    """Fetch a user by ID. Returns dict or raises on failure."""
    try:
        response = requests.get(
            f"https://jsonplaceholder.typicode.com/users/{user_id}",
            timeout=timeout    # seconds — always set this
        )
        response.raise_for_status()
        return response.json()

    except Timeout:
        raise RuntimeError(f"Request timed out after {timeout}s")
    except ConnectionError:
        raise RuntimeError("Could not connect to server")
    except HTTPError as e:
        status = e.response.status_code
        if status == 404:
            return None    # not found is expected — return None
        elif status == 401:
            raise PermissionError("Invalid or missing API key")
        elif status == 429:
            raise RuntimeError("Rate limited")
        else:
            raise RuntimeError(f"HTTP {status}: {e.response.text[:200]}")
    except RequestException as e:
        raise RuntimeError(f"Request failed: {e}") from e
```

**Always set a timeout.** Without it, a request to an unresponsive server hangs forever. `timeout=5` means: fail if no response within 5 seconds. You can also set `timeout=(3, 10)` — 3 seconds to connect, 10 seconds to receive.

---

## A reusable API client — the pattern used in real code

```python
import requests
from requests.exceptions import HTTPError, RequestException
import time


class APIClient:
    """
    Reusable HTTP client with auth, retries, and error handling.
    """

    def __init__(self, base_url, api_key=None, timeout=10, max_retries=3):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.max_retries = max_retries
        self._session = requests.Session()

        if api_key:
            self._session.headers.update({"Authorization": f"Bearer {api_key}"})
        self._session.headers.update({"Accept": "application/json"})

    def _request(self, method, endpoint, **kwargs):
        """Make a request with retry logic."""
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        last_error = None

        for attempt in range(1, self.max_retries + 1):
            try:
                response = self._session.request(
                    method, url, timeout=self.timeout, **kwargs
                )
                response.raise_for_status()
                return response

            except HTTPError as e:
                status = e.response.status_code
                if status == 429:
                    # Respect retry-after header if present
                    retry_after = int(e.response.headers.get("Retry-After", 2 ** attempt))
                    print(f"Rate limited. Waiting {retry_after}s...")
                    time.sleep(retry_after)
                    last_error = e
                    continue
                elif status >= 500 and attempt < self.max_retries:
                    time.sleep(2 ** attempt)    # exponential backoff
                    last_error = e
                    continue
                raise    # 4xx errors (except 429) — don't retry

            except RequestException as e:
                if attempt < self.max_retries:
                    time.sleep(2 ** attempt)
                    last_error = e
                    continue
                raise RuntimeError(f"Request failed after {attempt} attempts: {e}") from e

        raise RuntimeError(f"Max retries exceeded") from last_error

    def get(self, endpoint, params=None):
        return self._request("GET", endpoint, params=params).json()

    def post(self, endpoint, data):
        return self._request("POST", endpoint, json=data).json()

    def put(self, endpoint, data):
        return self._request("PUT", endpoint, json=data).json()

    def delete(self, endpoint):
        return self._request("DELETE", endpoint)

    def close(self):
        self._session.close()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.close()
```

---

## Working with a real API — JSONPlaceholder

JSONPlaceholder is a free fake REST API — no key needed, perfect for learning.

```python
BASE_URL = "https://jsonplaceholder.typicode.com"

with APIClient(BASE_URL) as client:

    # Get all users
    users = client.get("/users")
    print(f"Found {len(users)} users")
    for user in users[:3]:
        print(f"  {user['id']}: {user['name']} ({user['email']})")

    # Get one user
    user = client.get("/users/1")
    print(f"\nUser 1: {user['name']}")
    print(f"  Company: {user['company']['name']}")
    print(f"  City: {user['address']['city']}")

    # Get posts for user 1
    posts = client.get("/posts", params={"userId": 1})
    print(f"\nUser 1 has {len(posts)} posts")
    for post in posts[:2]:
        print(f"  [{post['id']}] {post['title'][:50]}")

    # Create a new post
    new_post = client.post("/posts", data={
        "title": "My new post",
        "body": "Post content here",
        "userId": 1
    })
    print(f"\nCreated post ID: {new_post['id']}")
```

---

## Parsing real API responses — what the data actually looks like

APIs rarely return clean flat objects. They nest, they use inconsistent types, they have optional fields. Writing defensive parsing is essential.

```python
def parse_github_repo(raw):
    """Parse a GitHub repo object — handles missing/null fields."""
    return {
        "id": raw["id"],
        "name": raw["full_name"],
        "description": raw.get("description") or "No description",
        "stars": raw.get("stargazers_count", 0),
        "language": raw.get("language", "Unknown"),
        "url": raw["html_url"],
        "open_issues": raw.get("open_issues_count", 0),
        "is_fork": raw.get("fork", False),
        "topics": raw.get("topics", []),    # may not exist
    }

def search_github_repos(query, sort="stars", limit=10):
    """Search GitHub repositories. No auth needed for public search."""
    with requests.Session() as session:
        session.headers.update({
            "Accept": "application/vnd.github.v3+json",
            "User-Agent": "Python-Learning-App"
        })
        response = session.get(
            "https://api.github.com/search/repositories",
            params={"q": query, "sort": sort, "per_page": limit},
            timeout=10
        )
        response.raise_for_status()
        data = response.json()

    repos = [parse_github_repo(r) for r in data["items"]]
    return repos, data["total_count"]

repos, total = search_github_repos("python web scraping", limit=5)
print(f"Found {total} repos. Top 5:\n")
for repo in repos:
    print(f"  ⭐ {repo['stars']:>6}  {repo['name']}")
    print(f"           {repo['description'][:60]}")
    print()
```

---

## Authentication patterns

APIs use different authentication methods. Know them all:

```python
# 1. API Key in header — most common
headers = {"X-API-Key": "your_key_here"}
headers = {"Authorization": "Bearer your_token_here"}

# 2. API Key in query parameter — less common, less secure
params = {"api_key": "your_key_here", "q": "search term"}

# 3. Basic auth — username + password
response = requests.get(url, auth=("username", "password"))
# or
from requests.auth import HTTPBasicAuth
response = requests.get(url, auth=HTTPBasicAuth("user", "pass"))

# 4. OAuth 2.0 token — most modern APIs
# Step 1: get a token (usually via a separate request)
token_response = requests.post(
    "https://api.example.com/oauth/token",
    data={"grant_type": "client_credentials",
          "client_id": "your_id",
          "client_secret": "your_secret"}
)
token = token_response.json()["access_token"]

# Step 2: use the token
headers = {"Authorization": f"Bearer {token}"}
```

**Keep API keys out of code.** Use environment variables:

```python
import os
from dotenv import load_dotenv

load_dotenv()

API_KEY = os.environ.get("OPENWEATHER_API_KEY")
if not API_KEY:
    raise RuntimeError("OPENWEATHER_API_KEY environment variable not set")
```

---

## Rate limiting — being a good API citizen

```python
import time
from functools import wraps

class RateLimitedClient(APIClient):
    """API client that respects rate limits automatically."""

    def __init__(self, *args, calls_per_second=1, **kwargs):
        super().__init__(*args, **kwargs)
        self._min_interval = 1.0 / calls_per_second
        self._last_call = 0.0

    def _request(self, method, endpoint, **kwargs):
        # Enforce rate limit
        elapsed = time.monotonic() - self._last_call
        wait = self._min_interval - elapsed
        if wait > 0:
            time.sleep(wait)
        self._last_call = time.monotonic()
        return super()._request(method, endpoint, **kwargs)


# 2 requests per second max
client = RateLimitedClient("https://api.example.com", calls_per_second=2)
```

---

## Handling pagination — getting all the data

Most APIs limit how many results they return per request. You need to handle pagination to get everything.

```python
def get_all_pages(client, endpoint, params=None, page_key="page", results_key="results"):
    """
    Generic paginator — fetches all pages from a paginated API.
    Yields individual items.
    """
    params = dict(params or {})
    page = 1

    while True:
        params[page_key] = page
        data = client.get(endpoint, params=params)

        # Handle different API pagination styles
        if isinstance(data, list):
            # Some APIs just return a list and stop when empty
            if not data:
                break
            yield from data
        elif isinstance(data, dict):
            items = data.get(results_key, data.get("data", data.get("items", [])))
            yield from items

            # Check if there are more pages
            if not data.get("has_more") and not data.get("next"):
                break
            if len(items) == 0:
                break

        page += 1
        time.sleep(0.1)    # be polite

# JSONPlaceholder example — it ignores page param but shows the pattern
with APIClient(BASE_URL) as client:
    all_comments = list(get_all_pages(client, "/comments"))
    print(f"Total comments: {len(all_comments)}")
```

---

## The mental model to carry forward

An API call is just an HTTP request — a structured message sent over a network, with a structured response back. The `requests` library handles all the low-level HTTP details so you focus on the data.

**The four things to always do:**

- Set a timeout — always
- Call `raise_for_status()` — always
- Use a Session for multiple requests to the same host — always
- Store API keys in environment variables — always

**The professional pattern:**

- Wrap API calls in a client class with auth and retry logic
- Parse responses with defensive `.get()` calls that handle missing fields
- Handle each error type explicitly — don't catch `Exception` and move on
- Respect rate limits — back off when you get 429s

Tomorrow is Day 18 — testing with pytest. You'll write tests for the API client and the task manager, and learn the mindset that separates reliable code from code that merely seems to work.

[[Intermediate Power]]