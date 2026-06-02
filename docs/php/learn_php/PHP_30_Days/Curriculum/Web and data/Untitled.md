# Day 16 — File uploads

File uploads are where security mistakes are most costly. A misconfigured upload handler can give an attacker arbitrary code execution on your server. Today you build it correctly from first principles — validate server-side, generate safe filenames, store outside the web root, serve through a controller.

## The mental model — never trust the client

```
Client sends:
  $_FILES["upload"]["name"]      → "photo.jpg"     ← attacker controls this
  $_FILES["upload"]["type"]      → "image/jpeg"     ← attacker controls this
  $_FILES["upload"]["size"]      → 12345            ← attacker controls this
  $_FILES["upload"]["tmp_name"]  → "/tmp/phpXXXXXX" ← PHP controls this ✓
  $_FILES["upload"]["error"]     → 0                ← PHP controls this ✓

Trust only tmp_name and error. Everything else is user input.
```

The browser sends the MIME type and filename — an attacker can send anything. Your job is to determine the real type by inspecting the file contents, generate a safe name yourself, and only then move the file to permanent storage.

## The HTML form

```html
<!-- enctype is mandatory for file uploads — without it, no file is sent -->
<form method="POST" action="/upload" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label>
        Machine photo
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
    </label>
    <button type="submit">Upload</button>
</form>
```

`accept` on the input is a UX hint — it filters the file picker dialog. It does nothing for security. PHP never sees it. Your server-side validation is what actually enforces the restriction.

## $_FILES — reading upload data correctly

```php
<?php
declare(strict_types=1);

// Always check the error code first — before touching anything else
function uploadErrorMessage(int $code): string {
    return match($code) {
        UPLOAD_ERR_OK         => "No error",
        UPLOAD_ERR_INI_SIZE   => "File exceeds upload_max_filesize in php.ini",
        UPLOAD_ERR_FORM_SIZE  => "File exceeds MAX_FILE_SIZE in the form",
        UPLOAD_ERR_PARTIAL    => "File only partially uploaded",
        UPLOAD_ERR_NO_FILE    => "No file was uploaded",
        UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
        UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
        UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the upload",
        default               => "Unknown upload error: $code",
    };
}

$file = $_FILES["photo"] ?? null;

if ($file === null || $file["error"] === UPLOAD_ERR_NO_FILE) {
    // No file submitted — handle based on whether upload is required
    die("No file uploaded.");
}

if ($file["error"] !== UPLOAD_ERR_OK) {
    die("Upload error: " . uploadErrorMessage($file["error"]));
}

// is_uploaded_file() — critical security check
// Verifies the file was actually uploaded via HTTP POST
// Prevents attackers from tricking your code into moving arbitrary files
if (!is_uploaded_file($file["tmp_name"])) {
    http_response_code(400);
    die("Invalid upload.");
}
```

`is_uploaded_file()` is not optional. Without it, an attacker who can influence `$_FILES["photo"]["tmp_name"]` could point it at any file on your server and your `move_uploaded_file()` call would move it.

## Validating file type — the right way

```php
<?php
declare(strict_types=1);

function detectMimeType(string $tmpPath): string {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmpPath);

    if ($mime === false) {
        throw new \RuntimeException("Could not determine file type.");
    }

    return $mime;
}

function validateImageUpload(array $file): array {
    // Allowed types and their canonical extensions
    $allowed = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp",
        "image/gif"  => "gif",
    ];

    $maxBytes = 5 * 1024 * 1024;   // 5 MB

    if ($file["error"] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException(uploadErrorMessage($file["error"]));
    }

    if (!is_uploaded_file($file["tmp_name"])) {
        throw new \RuntimeException("Invalid upload source.");
    }

    // Size check against actual file, not client-reported size
    $actualSize = filesize($file["tmp_name"]);
    if ($actualSize === false || $actualSize > $maxBytes) {
        throw new \RuntimeException("File too large. Maximum size is 5 MB.");
    }

    // Detect real MIME type from file contents — ignore $_FILES["type"]
    $mimeType = detectMimeType($file["tmp_name"]);

    if (!array_key_exists($mimeType, $allowed)) {
        throw new \RuntimeException("File type not allowed: $mimeType. Accepted: JPEG, PNG, WebP, GIF.");
    }

    return [
        "tmp_name"  => $file["tmp_name"],
        "mime_type" => $mimeType,
        "extension" => $allowed[$mimeType],
        "size"      => $actualSize,
    ];
}
```

Two things to notice: size is checked against `filesize($file["tmp_name"])` — the actual file on disk — not `$file["size"]`, which the client reported and can lie about. MIME type is detected from file contents using `finfo` — not from `$file["type"]`, which is whatever the browser sent.

## Generating a safe filename

```php
<?php
declare(strict_types=1);

function generateFilename(string $extension, string $prefix = ""): string {
    // bin2hex(random_bytes(16)) = 32-character hex string — cryptographically random
    $random = bin2hex(random_bytes(16));
    $prefix = $prefix !== "" ? $prefix . "_" : "";
    return $prefix . $random . "." . $extension;
}

// Examples:
// generateFilename("jpg")           → "a3f9c2b1d4e5f678901234567890abcd.jpg"
// generateFilename("png", "avatar") → "avatar_b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7.png"

// Never use the original filename — it can contain:
// - Path separators: ../../etc/passwd.jpg
// - Null bytes:      shell.php%00.jpg  (old PHP vulnerability)
// - Reserved names:  CON, PRN, AUX on Windows
// - Unicode tricks:  file‮gpj.exe (right-to-left override)
// - Shell metacharacters: file$(whoami).jpg
```

## Storage — outside the web root

```
web root: /var/www/fleet/public/     ← accessible via HTTP
uploads:  /var/www/fleet/storage/    ← NOT accessible via HTTP
```

Files stored inside the web root can be accessed directly by URL. If an attacker uploads a PHP file disguised as an image, they can execute it by navigating to its URL. Store uploads outside the web root and serve them through a PHP controller that validates access:

```php
<?php
declare(strict_types=1);

define("STORAGE_PATH", dirname(__DIR__) . "/storage/uploads/");
define("WEB_ROOT",     __DIR__);   // public/

// Create storage directory if it doesn't exist
if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0750, true);
}

// Move uploaded file to permanent storage
function storeUpload(array $validated): string {
    $filename = generateFilename($validated["extension"]);
    $dest     = STORAGE_PATH . $filename;

    if (!move_uploaded_file($validated["tmp_name"], $dest)) {
        throw new \RuntimeException("Failed to move uploaded file.");
    }

    // Set restrictive permissions — not executable
    chmod($dest, 0640);

    return $filename;
}
```

`move_uploaded_file` is the only correct way to move an uploaded file. It verifies the source was actually an HTTP upload (same check as `is_uploaded_file`) and atomically moves the temp file. Never use `copy()` or `rename()` for uploads.

## The download/serve controller

Since files aren't in the web root, you serve them through PHP:

```php
<?php
// handlers/files/download.php
declare(strict_types=1);

requireLogin();

$filename = $_GET["file"] ?? "";

// Whitelist check — only alphanumeric, hyphens, underscores, one dot
if (!preg_match('/^[a-zA-Z0-9_-]+\.[a-zA-Z]{2,5}$/', $filename)) {
    http_response_code(400);
    echo "Invalid filename.";
    exit;
}

$path = STORAGE_PATH . $filename;

// Resolve real path and confirm it stays inside storage
$realStorage = realpath(STORAGE_PATH);
$realPath    = realpath($path);

if ($realPath === false || !str_starts_with($realPath, $realStorage . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

// Optional: check this file belongs to the current user
// $record = Database::fetch("SELECT * FROM uploads WHERE filename = :f AND user_id = :u", ...)
// if (!$record) { http_response_code(403); exit; }

// Detect real MIME type for Content-Type header
$finfo    = new \finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($realPath);

// Stream the file
header("Content-Type: $mimeType");
header("Content-Length: " . filesize($realPath));
header("Content-Disposition: inline; filename=\"" . basename($realPath) . "\"");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=3600");

readfile($realPath);
exit;
```

The double path validation — filename regex plus `realpath()` prefix check — gives you two independent layers. The regex rejects anything that looks suspicious before you even touch the filesystem. The `realpath()` check catches anything that slipped through.

## Image resizing — GD library

Processing images server-side has two benefits: you strip potentially malicious metadata (EXIF data can contain XSS payloads for some parsers), and you enforce consistent dimensions:

```php
<?php
declare(strict_types=1);

function resizeImage(
    string $sourcePath,
    string $destPath,
    int    $maxWidth,
    int    $maxHeight,
    int    $quality = 85
): void {
    $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);

    // Load source image
    $source = match($mimeType) {
        "image/jpeg" => imagecreatefromjpeg($sourcePath),
        "image/png"  => imagecreatefrompng($sourcePath),
        "image/webp" => imagecreatefromwebp($sourcePath),
        "image/gif"  => imagecreatefromgif($sourcePath),
        default      => throw new \RuntimeException("Unsupported image type: $mimeType"),
    };

    if ($source === false) {
        throw new \RuntimeException("Failed to load image: $sourcePath");
    }

    $origWidth  = imagesx($source);
    $origHeight = imagesy($source);

    // Calculate new dimensions preserving aspect ratio
    $ratio      = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1.0);
    $newWidth   = (int)round($origWidth  * $ratio);
    $newHeight  = (int)round($origHeight * $ratio);

    // Create resized canvas
    $dest = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if (in_array($mimeType, ["image/png", "image/gif"], true)) {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Resample — better quality than imagecopyresized
    imagecopyresampled(
        $dest, $source,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $origWidth, $origHeight
    );

    // Save as JPEG (strips EXIF metadata automatically)
    imagejpeg($dest, $destPath, $quality);

    // Free memory
    imagedestroy($source);
    imagedestroy($dest);
}
```

`imagejpeg()` strips all EXIF metadata from the output. This is a security benefit — EXIF can contain GPS coordinates, device information, and in some edge cases, exploitable data for vulnerable EXIF parsers.

## Complete upload handler

```php
<?php
// handlers/files/upload.php
declare(strict_types=1);

requireLogin();
verifyCsrf();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

$machineId = trim($_POST["machine_id"] ?? "");

if ($machineId === "") {
    http_response_code(400);
    die("Machine ID required.");
}

// Confirm machine exists and belongs to this user's scope
$machine = Database::fetch(
    "SELECT id FROM machines WHERE id = :id",
    [":id" => $machineId]
);

if ($machine === false) {
    http_response_code(404);
    die("Machine not found.");
}

try {
    // Validate
    $validated = validateImageUpload($_FILES["photo"]);

    // Store original
    $filename = storeUpload($validated);

    // Resize to thumbnail
    $thumbName = "thumb_" . $filename;
    resizeImage(
        STORAGE_PATH . $filename,
        STORAGE_PATH . $thumbName,
        400, 400,
        85
    );

    // Save record to database
    Database::execute("
        INSERT INTO machine_photos (machine_id, filename, thumb_filename,
                                    mime_type, size_bytes, uploaded_by)
        VALUES (:machine_id, :filename, :thumb, :mime, :size, :user_id)
    ", [
        ":machine_id" => $machineId,
        ":filename"   => $filename,
        ":thumb"      => $thumbName,
        ":mime"       => $validated["mime_type"],
        ":size"       => $validated["size"],
        ":user_id"    => $_SESSION["user_id"],
    ]);

    $_SESSION["flash"] = ["type" => "success", "message" => "Photo uploaded successfully."];
    header("Location: /machines/view?id=" . urlencode($machineId));
    exit;

} catch (\RuntimeException $e) {
    $_SESSION["flash"] = ["type" => "error", "message" => $e->getMessage()];
    header("Location: /machines/view?id=" . urlencode($machineId));
    exit;
}
```

## php.ini settings for uploads

```ini
; php.ini — or set via ini_set() in your bootstrap
upload_max_filesize = 10M     ; max single file size
post_max_size       = 12M     ; must be larger than upload_max_filesize
max_file_uploads    = 5       ; max files per request
file_uploads        = On      ; enable uploads at all
```

`post_max_size` must be larger than `upload_max_filesize` — the POST body contains the file plus form fields. If `post_max_size` is smaller, PHP silently discards the entire POST body, `$_FILES` is empty, and the error code is `UPLOAD_ERR_OK` with no file. This is a confusing failure mode worth knowing.

---

## Today's exercisePart B is the one that makes today's lesson permanent — attacking your own code and watching the defenses hold is a completely different experience from just writing the defenses. Every attack attempt that fails correctly builds the right instinct for what secure code feels like.

The delete order in the stretch goal hint matters: fetch → verify ownership → unlink files → delete DB row. If you delete the DB row first and then `unlink` fails, you have files on disk with no record — invisible orphans that accumulate. If you `unlink` first and the DB delete fails, you can re-upload. The filesystem state is always recoverable; the DB state is what controls access.

Day 17 is the Phase 2 capstone — a complete blog engine combining auth, CRUD, file uploads, sessions, security, and everything else from the last eight days into one coherent project. Paste your code when ready or call Day 17 to start the capstone.