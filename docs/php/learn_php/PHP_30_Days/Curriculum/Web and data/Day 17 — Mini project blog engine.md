
This is your Phase 2 capstone. No new concepts today — everything comes from Days 9–16. The goal is to build a complete, working application that combines all of it into one coherent codebase. When you finish, you'll have something real to point to and a clear sense of how the pieces fit together before Phase 3 introduces OOP and architecture.

## What you're building

A blog engine where authenticated users can write and publish posts. Visitors can read posts without logging in. The URL structure uses slugs (`/post/my-article-title`) rather than IDs.

## The schema

```sql
-- Run these in MySQL
CREATE TABLE posts (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_id    INT UNSIGNED  NOT NULL,
    title        VARCHAR(200)  NOT NULL,
    slug         VARCHAR(210)  NOT NULL UNIQUE,
    body         TEXT          NOT NULL,
    status       ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at DATETIME      NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status_published (status, published_at),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_images (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id    INT UNSIGNED NOT NULL,
    filename   VARCHAR(100) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Folder structure

```
public/              ← web root (php -S localhost:8000 index.php)
  index.php          ← front controller
  bootstrap.php      ← session, auth, DB, security headers
  db.php             ← Database class
  auth.php           ← isLoggedIn, requireLogin, csrfToken, verifyCsrf
  handlers/
    home.php
    auth/
      login.php
      register.php
      logout.php
    posts/
      list.php       ← public post listing
      show.php       ← single post by slug
      admin/
        list.php     ← admin: all my posts
        create.php   ← create post
        edit.php     ← edit post
        delete.php   ← delete post
  templates/
    layout.php
    home.html.php
    auth/
      login.html.php
      register.html.php
    posts/
      list.html.php
      show.html.php
      admin/
        list.html.php
        form.html.php
storage/
  uploads/           ← post images (outside web root)
```

## The front controller

```php
<?php
// public/index.php
declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
$uri    = rtrim(strtok($_SERVER["REQUEST_URI"], "?"), "/") ?: "/";

$routes = [
    ["GET",  "/",                    "handlers/home.php"],
    ["GET",  "/blog",                "handlers/posts/list.php"],
    ["GET",  "/post",                "handlers/posts/show.php"],   // ?slug=my-post
    ["GET",  "/login",               "handlers/auth/login.php"],
    ["POST", "/login",               "handlers/auth/login.php"],
    ["GET",  "/register",            "handlers/auth/register.php"],
    ["POST", "/register",            "handlers/auth/register.php"],
    ["POST", "/logout",              "handlers/auth/logout.php"],
    ["GET",  "/admin/posts",         "handlers/posts/admin/list.php"],
    ["GET",  "/admin/posts/new",     "handlers/posts/admin/create.php"],
    ["POST", "/admin/posts",         "handlers/posts/admin/create.php"],
    ["GET",  "/admin/posts/edit",    "handlers/posts/admin/edit.php"],
    ["POST", "/admin/posts/update",  "handlers/posts/admin/edit.php"],
    ["POST", "/admin/posts/delete",  "handlers/posts/admin/delete.php"],
    ["GET",  "/download",            "handlers/files/download.php"],
];

$handler = null;
foreach ($routes as [$rm, $ru, $rh]) {
    if ($method === $rm && $uri === $ru) {
        $handler = $rh;
        break;
    }
}

if ($handler === null || !file_exists($handler)) {
    http_response_code(404);
    $content = "<h1>404 — Page not found</h1><p><a href='/blog'>Back to blog</a></p>";
    $title   = "Not found";
    require "templates/layout.php";
    exit;
}

require $handler;
```

## Bootstrap

```php
<?php
// public/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

define("STORAGE_PATH", dirname(__DIR__) . "/storage/uploads/");

if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0750, true);
}

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'");
header_remove("X-Powered-By");

// Session config
ini_set("session.cookie_httponly", "1");
ini_set("session.cookie_samesite", "Lax");
ini_set("session.use_strict_mode", "1");
ini_set("session.gc_maxlifetime", "3600");

session_start();

// Active timeout
if (isLoggedIn()) {
    $idle = time() - ($_SESSION["last_activity"] ?? time());
    if ($idle > 1800) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $_SESSION["flash"] = ["type" => "info", "message" => "Session expired. Please log in again."];
        header("Location: /login");
        exit;
    }
    $_SESSION["last_activity"] = time();
}
```

## The slug helper

```php
<?php
// In auth.php or a new helpers.php — require_once'd by bootstrap

function slugify(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
    $text = preg_replace('/[\s_]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

function uniqueSlug(string $title, ?int $excludeId = null): string {
    $base = slugify($title);
    $slug = $base;
    $i    = 1;

    while (true) {
        $sql    = "SELECT COUNT(*) FROM posts WHERE slug = :slug";
        $params = [":slug" => $slug];

        if ($excludeId !== null) {
            $sql    .= " AND id != :id";
            $params[":id"] = $excludeId;
        }

        $count = (int)Database::fetchColumn($sql, $params);

        if ($count === 0) break;

        $slug = $base . "-" . $i;
        $i++;
    }

    return $slug;
}
```

`uniqueSlug` handles collisions — if "my-post" exists, it tries "my-post-2", "my-post-3" and so on. The `$excludeId` parameter makes it work correctly for edits — the existing post's own slug doesn't count as a collision.

## Public post listing

```php
<?php
// handlers/posts/list.php
declare(strict_types=1);

$page    = max(1, (int)($_GET["page"] ?? 1));
$perPage = 5;
$offset  = ($page - 1) * $perPage;

$total = (int)Database::fetchColumn("
    SELECT COUNT(*) FROM posts WHERE status = 'published'
");

$posts = Database::fetchAll("
    SELECT p.id, p.title, p.slug, p.published_at,
           u.username AS author
    FROM posts p
    JOIN users u ON u.id = p.author_id
    WHERE p.status = 'published'
    ORDER BY p.published_at DESC
    LIMIT :limit OFFSET :offset
", [":limit" => $perPage, ":offset" => $offset]);

// PDO needs explicit int binding for LIMIT/OFFSET — use query() with integers
// Or use the connection directly for this query
$stmt = Database::connection()->prepare("
    SELECT p.id, p.title, p.slug, p.published_at,
           u.username AS author
    FROM posts p
    JOIN users u ON u.id = p.author_id
    WHERE p.status = 'published'
    ORDER BY p.published_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute();
$posts = $stmt->fetchAll();

$totalPages = (int)ceil($total / $perPage);

ob_start();
require __DIR__ . "/../../templates/posts/list.html.php";
$content = ob_get_clean();
$title   = "Blog";
require __DIR__ . "/../../templates/layout.php";
```

Note on LIMIT/OFFSET with PDO: named parameters bind as strings by default. MySQL's LIMIT clause requires integers. The cleanest solution for simple pagination is to interpolate the already-validated integers directly into the SQL string, since they come from your own `(int)` cast, not user input.

## Single post

```php
<?php
// handlers/posts/show.php
declare(strict_types=1);

$slug = trim($_GET["slug"] ?? "");

if ($slug === "") {
    http_response_code(400);
    echo "No post specified.";
    exit;
}

$post = Database::fetch("
    SELECT p.*, u.username AS author
    FROM posts p
    JOIN users u ON u.id = p.author_id
    WHERE p.slug = :slug AND p.status = 'published'
", [":slug" => $slug]);

if ($post === false) {
    http_response_code(404);
    $content = "<h1>Post not found</h1><p><a href='/blog'>Back to blog</a></p>";
    $title   = "Not found";
    require __DIR__ . "/../../templates/layout.php";
    exit;
}

// Fetch images for this post
$images = Database::fetchAll(
    "SELECT filename FROM post_images WHERE post_id = :id ORDER BY id",
    [":id" => $post["id"]]
);

ob_start();
require __DIR__ . "/../../templates/posts/show.html.php";
$content = ob_get_clean();
$title   = $post["title"] . " — Blog";
require __DIR__ . "/../../templates/layout.php";
```

## Admin — create post

```php
<?php
// handlers/posts/admin/create.php
declare(strict_types=1);

requireLogin();

$errors = [];
$values = ["title" => "", "body" => "", "status" => "draft"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verifyCsrf();

    $values["title"]  = trim($_POST["title"]  ?? "");
    $values["body"]   = trim($_POST["body"]   ?? "");
    $values["status"] = trim($_POST["status"] ?? "draft");

    if ($values["title"] === "") {
        $errors["title"] = "Title is required.";
    } elseif (strlen($values["title"]) > 200) {
        $errors["title"] = "Title must be 200 characters or fewer.";
    }

    if ($values["body"] === "") {
        $errors["body"] = "Body is required.";
    }

    if (!in_array($values["status"], ["draft", "published"], true)) {
        $errors["status"] = "Invalid status.";
    }

    if (empty($errors)) {
        $slug        = uniqueSlug($values["title"]);
        $publishedAt = $values["status"] === "published" ? date("Y-m-d H:i:s") : null;

        Database::execute("
            INSERT INTO posts (author_id, title, slug, body, status, published_at)
            VALUES (:author_id, :title, :slug, :body, :status, :published_at)
        ", [
            ":author_id"    => $_SESSION["user_id"],
            ":title"        => $values["title"],
            ":slug"         => $slug,
            ":body"         => $values["body"],
            ":status"       => $values["status"],
            ":published_at" => $publishedAt,
        ]);

        $postId = (int)Database::connection()->lastInsertId();

        // Handle optional image upload
        if (!empty($_FILES["image"]["name"])) {
            try {
                $validated = validateImageUpload($_FILES["image"]);
                $filename  = storeUpload($validated);

                Database::execute(
                    "INSERT INTO post_images (post_id, filename) VALUES (:post_id, :filename)",
                    [":post_id" => $postId, ":filename" => $filename]
                );
            } catch (\RuntimeException $e) {
                // Post saved — image failed — non-fatal, flash a warning
                $_SESSION["flash"] = ["type" => "error", "message" => "Post saved but image upload failed: " . $e->getMessage()];
                header("Location: /admin/posts");
                exit;
            }
        }

        $_SESSION["flash"] = ["type" => "success", "message" => "Post '{$values['title']}' saved."];
        header("Location: /admin/posts");
        exit;
    }
}

ob_start();
require __DIR__ . "/../../../templates/posts/admin/form.html.php";
$content = ob_get_clean();
$title   = "New Post";
require __DIR__ . "/../../../templates/layout.php";
```

## Admin — edit post

```php
<?php
// handlers/posts/admin/edit.php
declare(strict_types=1);

requireLogin();

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $id   = (int)($_GET["id"] ?? 0);
    $post = Database::fetch(
        "SELECT * FROM posts WHERE id = :id AND author_id = :uid",
        [":id" => $id, ":uid" => $_SESSION["user_id"]]
    );

    if ($post === false) {
        http_response_code(404);
        echo "Post not found or access denied.";
        exit;
    }

    $values = [
        "title"  => $post["title"],
        "body"   => $post["body"],
        "status" => $post["status"],
    ];
    $errors  = [];
    $isEdit  = true;

    ob_start();
    require __DIR__ . "/../../../templates/posts/admin/form.html.php";
    $content = ob_get_clean();
    $title   = "Edit: " . $post["title"];
    require __DIR__ . "/../../../templates/layout.php";
    exit;
}

// POST — save changes
verifyCsrf();

$id     = (int)($_POST["id"] ?? 0);
$errors = [];
$values = [
    "title"  => trim($_POST["title"]  ?? ""),
    "body"   => trim($_POST["body"]   ?? ""),
    "status" => trim($_POST["status"] ?? "draft"),
];

// Verify ownership before updating
$existing = Database::fetch(
    "SELECT * FROM posts WHERE id = :id AND author_id = :uid",
    [":id" => $id, ":uid" => $_SESSION["user_id"]]
);

if ($existing === false) {
    http_response_code(403);
    die("Access denied.");
}

if ($values["title"] === "") {
    $errors["title"] = "Title is required.";
}

if ($values["body"] === "") {
    $errors["body"] = "Body is required.";
}

if (empty($errors)) {
    // Only regenerate slug if title changed
    $slug = $existing["slug"];
    if ($values["title"] !== $existing["title"]) {
        $slug = uniqueSlug($values["title"], $id);
    }

    $publishedAt = $existing["published_at"];
    if ($values["status"] === "published" && $existing["status"] === "draft") {
        $publishedAt = date("Y-m-d H:i:s");   // first time publishing
    }

    Database::execute("
        UPDATE posts
        SET title = :title, slug = :slug, body = :body,
            status = :status, published_at = :published_at
        WHERE id = :id
    ", [
        ":title"        => $values["title"],
        ":slug"         => $slug,
        ":body"         => $values["body"],
        ":status"       => $values["status"],
        ":published_at" => $publishedAt,
        ":id"           => $id,
    ]);

    $_SESSION["flash"] = ["type" => "success", "message" => "Post updated."];
    header("Location: /admin/posts");
    exit;
}

$isEdit = true;
$post   = $existing;
ob_start();
require __DIR__ . "/../../../templates/posts/admin/form.html.php";
$content = ob_get_clean();
$title   = "Edit: " . $values["title"];
require __DIR__ . "/../../../templates/layout.php";
```

## Admin — delete post

```php
<?php
// handlers/posts/admin/delete.php
declare(strict_types=1);

requireLogin();
verifyCsrf();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

$id = (int)($_POST["id"] ?? 0);

// Verify ownership
$post = Database::fetch(
    "SELECT id, title FROM posts WHERE id = :id AND author_id = :uid",
    [":id" => $id, ":uid" => $_SESSION["user_id"]]
);

if ($post === false) {
    http_response_code(403);
    die("Access denied.");
}

// Delete associated images from disk first
$images = Database::fetchAll(
    "SELECT filename FROM post_images WHERE post_id = :id",
    [":id" => $id]
);

foreach ($images as $image) {
    $path = STORAGE_PATH . $image["filename"];
    if (file_exists($path)) {
        unlink($path);
    }
}

// Delete the post — CASCADE handles post_images rows
Database::execute("DELETE FROM posts WHERE id = :id", [":id" => $id]);

$_SESSION["flash"] = ["type" => "success", "message" => "Post '{$post['title']}' deleted."];
header("Location: /admin/posts");
exit;
```

## Key templates

```php
<?php
// templates/layout.php
declare(strict_types=1);

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($title ?? "Blog") ?></title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem;line-height:1.6}
        nav{display:flex;gap:1rem;margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid #eee}
        nav a{text-decoration:none;color:#333}
        nav a:hover{color:#000}
        .flash-success{background:#d4edda;color:#155724;padding:.75rem;margin:1rem 0;border-radius:4px}
        .flash-error{background:#f8d7da;color:#721c24;padding:.75rem;margin:1rem 0;border-radius:4px}
        .flash-info{background:#d1ecf1;color:#0c5460;padding:.75rem;margin:1rem 0;border-radius:4px}
        .error-msg{color:#c00;font-size:.875rem}
        label{display:block;margin:.75rem 0 .25rem;font-weight:500}
        input,textarea,select{width:100%;padding:.4rem;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font:inherit}
        textarea{min-height:300px;resize:vertical}
        .btn{padding:.4rem .9rem;cursor:pointer;border-radius:4px}
        .btn-danger{background:#dc3545;color:#fff;border:none}
        article{margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid #eee}
        article h2{margin-bottom:.25rem}
        .meta{color:#666;font-size:.875rem;margin-bottom:.75rem}
        .status-draft{color:#856404;background:#fff3cd;padding:2px 6px;border-radius:3px;font-size:.8rem}
        .status-published{color:#155724;background:#d4edda;padding:2px 6px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<nav>
    <a href="/">Home</a>
    <a href="/blog">Blog</a>
    <?php if (isLoggedIn()): ?>
        <a href="/admin/posts">My posts</a>
        <a href="/admin/posts/new">New post</a>
        <span style="margin-left:auto;color:#666"><?= e($_SESSION["username"]) ?></span>
        <form method="POST" action="/logout" style="margin:0">
            <?= csrfField() ?>
            <button type="submit" style="background:none;border:none;cursor:pointer;color:#666">Log out</button>
        </form>
    <?php else: ?>
        <a href="/login" style="margin-left:auto">Log in</a>
        <a href="/register">Register</a>
    <?php endif; ?>
</nav>

<?php
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);
if ($flash):
?>
    <div class="flash-<?= e($flash["type"]) ?>"><?= e($flash["message"]) ?></div>
<?php endif; ?>

<?= $content ?>
</body>
</html>
```

```php
<?php
// templates/posts/list.html.php
declare(strict_types=1);
?>
<h1>Blog</h1>

<?php if (empty($posts)): ?>
    <p>No posts yet. <?= isLoggedIn() ? '<a href="/admin/posts/new">Write the first one.</a>' : '' ?></p>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <article>
            <h2><a href="/post?slug=<?= e($post["slug"]) ?>"><?= e($post["title"]) ?></a></h2>
            <p class="meta">
                By <?= e($post["author"]) ?> &middot;
                <?= e(date("j F Y", strtotime($post["published_at"]))) ?>
            </p>
        </article>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <nav style="display:flex;gap:.5rem;margin-top:2rem">
            <?php if ($page > 1): ?>
                <a href="/blog?page=<?= $page - 1 ?>">&larr; Previous</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="/blog?page=<?= $page + 1 ?>">Next &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
```

```php
<?php
// templates/posts/show.html.php
declare(strict_types=1);
?>
<article>
    <h1><?= e($post["title"]) ?></h1>
    <p class="meta">
        By <?= e($post["author"]) ?> &middot;
        <?= e(date("j F Y", strtotime($post["published_at"]))) ?>
        <?php if (isLoggedIn() && (int)$post["author_id"] === (int)$_SESSION["user_id"]): ?>
            &middot; <a href="/admin/posts/edit?id=<?= (int)$post["id"] ?>">Edit</a>
        <?php endif; ?>
    </p>

    <?php if (!empty($images)): ?>
        <div style="margin-bottom:1.5rem">
            <?php foreach ($images as $img): ?>
                <img src="/download?file=<?= e($img["filename"]) ?>"
                     alt=""
                     style="max-width:100%;border-radius:4px;margin-bottom:.5rem">
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="post-body">
        <?= nl2br(e($post["body"])) ?>
    </div>
</article>

<p><a href="/blog">&larr; Back to blog</a></p>
```

```php
<?php
// templates/posts/admin/form.html.php
declare(strict_types=1);

$isEdit = $isEdit ?? false;
?>
<h1><?= $isEdit ? "Edit post" : "New post" ?></h1>

<form method="POST"
      action="<?= $isEdit ? '/admin/posts/update' : '/admin/posts' ?>"
      enctype="multipart/form-data">
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$post["id"] ?>">
    <?php endif; ?>

    <label>Title
        <input type="text" name="title" value="<?= e($values["title"]) ?>" required>
        <?php if (isset($errors["title"])): ?>
            <span class="error-msg"><?= e($errors["title"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Body
        <textarea name="body" required><?= e($values["body"]) ?></textarea>
        <?php if (isset($errors["body"])): ?>
            <span class="error-msg"><?= e($errors["body"]) ?></span>
        <?php endif; ?>
    </label>

    <label>Status
        <select name="status">
            <option value="draft"     <?= $values["status"] === "draft"     ? "selected" : "" ?>>Draft</option>
            <option value="published" <?= $values["status"] === "published" ? "selected" : "" ?>>Published</option>
        </select>
    </label>

    <?php if (!$isEdit): ?>
        <label>Image (optional — JPEG, PNG, WebP, max 5MB)
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </label>
    <?php endif; ?>

    <br>
    <button type="submit" class="btn">
        <?= $isEdit ? "Save changes" : "Create post" ?>
    </button>
    <a href="/admin/posts">Cancel</a>
</form>
```

```php
<?php
// templates/posts/admin/list.html.php
declare(strict_types=1);
?>
<h1>My posts</h1>
<p><a href="/admin/posts/new">+ New post</a></p>

<?php if (empty($posts)): ?>
    <p>You have no posts yet. <a href="/admin/posts/new">Write your first one.</a></p>
<?php else: ?>
    <table style="width:100%;border-collapse:collapse">
        <thead>
            <tr style="border-bottom:2px solid #eee">
                <th style="text-align:left;padding:.5rem">Title</th>
                <th style="text-align:left;padding:.5rem">Status</th>
                <th style="text-align:left;padding:.5rem">Date</th>
                <th style="padding:.5rem">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
            <tr style="border-bottom:1px solid #eee">
                <td style="padding:.5rem">
                    <?php if ($p["status"] === "published"): ?>
                        <a href="/post?slug=<?= e($p["slug"]) ?>"><?= e($p["title"]) ?></a>
                    <?php else: ?>
                        <?= e($p["title"]) ?>
                    <?php endif; ?>
                </td>
                <td style="padding:.5rem">
                    <span class="status-<?= e($p["status"]) ?>"><?= e($p["status"]) ?></span>
                </td>
                <td style="padding:.5rem">
                    <?= e(date("Y-m-d", strtotime($p["updated_at"]))) ?>
                </td>
                <td style="padding:.5rem;text-align:center">
                    <a href="/admin/posts/edit?id=<?= (int)$p["id"] ?>">Edit</a>
                    &nbsp;
                    <form method="POST" action="/admin/posts/delete" style="display:inline"
                          onsubmit="return confirm('Delete \'<?= e(addslashes($p["title"])) ?>\'?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= (int)$p["id"] ?>">
                        <button type="submit" class="btn btn-danger">Delete</button>
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

![[Pasted image 20260602233541.png]]

Build it in the four steps above — foundation first, then public pages, then admin. That order matters: building reads before writes means you can verify the data model is right before you build the forms that produce data.

The acceptance checklist is your definition of done for Phase 2. Don't move to Phase 3 until every item passes. Some of them will catch things you've missed — ownership checking on delete is the one most people initially forget, and it's exactly the kind of bug that causes real security incidents.

When you submit for review paste the handlers you're least confident about — typically `edit.php` and `delete.php` where ownership verification, slug logic, and CSRF all have to work together correctly. Phase 3 starts on Day 18 with OOP, where everything you've built procedurally gets a cleaner architectural shape.