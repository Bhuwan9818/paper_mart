<?php
// ajax/upload-product-images.php
// Accepts image files via POST, saves them, returns filenames as JSON.
// Called by JS immediately when vendor selects/drops images.

// IMPORTANT: never let stray PHP warnings/notices leak into the response.
// Even a single unsuppressed notice printed before our json_encode() would
// corrupt the JSON and make fetch().then(r=>r.json()) throw — which looks
// to the user like the upload "blinked and vanished" with no real error.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Discard any accidental output (warnings, BOM, whitespace) emitted by the
// includes above, then send a clean JSON header.
ob_end_clean();
header('Content-Type: application/json');

// Must be logged in as vendor (or admin)
if (!isLoggedIn() || !in_array($_SESSION['role'], ['vendor', 'admin'])) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorised — please log in again and retry.']);
    exit;
}

// CSRF check via header or POST field
$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid session, please reload the page and try again.']);
    exit;
}

// Ensure upload directory exists and is writable
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}
if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
    echo json_encode(['ok' => false, 'msg' => 'Server upload folder is missing or not writable: ' . UPLOAD_DIR]);
    exit;
}

$saved = [];
$errors = [];
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 5 * 1024 * 1024; // 5 MB

$files = $_FILES['images'] ?? [];
if (empty($files['tmp_name'])) {
    echo json_encode(['ok' => false, 'msg' => 'No files received by server.']);
    exit;
}

$tmpArr  = (array)$files['tmp_name'];
$nameArr = (array)$files['name'];
$errArr  = (array)$files['error'];
$sizeArr = (array)$files['size'];

foreach ($tmpArr as $k => $tmp) {
    if ($errArr[$k] !== UPLOAD_ERR_OK) {
        $errors[] = ($nameArr[$k] ?? "File #{$k}") . ': upload error code ' . $errArr[$k];
        continue;
    }
    if (!is_uploaded_file($tmp)) {
        $errors[] = ($nameArr[$k] ?? "File #{$k}") . ': not a valid upload';
        continue;
    }
    if ($sizeArr[$k] > $maxSize) {
        $errors[] = ($nameArr[$k] ?? "File #{$k}") . ': exceeds 5 MB limit';
        continue;
    }

    // Detect real MIME from file contents
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    if (!in_array($mime, $allowedMimes)) {
        $errors[] = ($nameArr[$k] ?? "File #{$k}") . ': unsupported type (' . $mime . ')';
        continue;
    }

    // Build safe filename
    $rawExt = pathinfo($nameArr[$k] ?? '', PATHINFO_EXTENSION);
    $ext    = preg_replace('/[^a-zA-Z0-9]/', '', $rawExt) ?: 'jpg';
    $fn     = 'prod_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $dest   = UPLOAD_DIR . $fn;

    if (move_uploaded_file($tmp, $dest)) {
        @chmod($dest, 0644);
        $saved[] = $fn;
    } else {
        $errors[] = ($nameArr[$k] ?? "File #{$k}") . ': could not save (check folder permissions)';
    }
}

echo json_encode([
    'ok'     => count($saved) > 0,
    'saved'  => $saved,                        // filenames stored on disk
    'urls'   => array_map(fn($f) => UPLOAD_URL . $f, $saved), // public URLs for preview
    'msg'    => empty($saved) && !empty($errors) ? implode(' | ', $errors) : '',
    'errors' => $errors,
]);
