<?php
// ajax/upload-tds.php — Upload a TDS (Technical Data Sheet) PDF for a pricing row
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

ob_end_clean();
header('Content-Type: application/json');

if (!isLoggedIn() || !in_array($_SESSION['role'], ['vendor','admin'])) {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorised']); exit;
}

$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token']); exit;
}

$file = $_FILES['tds'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    echo json_encode(['ok'=>false,'msg'=>'No file received or upload error.']); exit;
}

// Max 10 MB
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'msg'=>'File too large. Max 10 MB.']); exit;
}

// Allow PDF, DOC, DOCX, XLS, XLSX
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($file['tmp_name']);
$allowed  = ['application/pdf','application/msword',
             'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
             'application/vnd.ms-excel',
             'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
if (!in_array($mime, $allowed)) {
    echo json_encode(['ok'=>false,'msg'=>'Only PDF, DOC, DOCX, XLS, XLSX files allowed.']); exit;
}

$tdsDir = __DIR__ . '/../assets/tds/';
if (!is_dir($tdsDir)) mkdir($tdsDir, 0755, true);

$ext  = strtolower(preg_replace('/[^a-z0-9]/i','',pathinfo($file['name'],PATHINFO_EXTENSION))) ?: 'pdf';
$fn   = 'tds_'.bin2hex(random_bytes(8)).'.'.$ext;
$dest = $tdsDir . $fn;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['ok'=>false,'msg'=>'Could not save file. Check folder permissions for assets/tds/']); exit;
}

echo json_encode([
    'ok'       => true,
    'filename' => $fn,
    'url'      => BASE_URL . '/assets/tds/' . $fn,
    'name'     => $file['name'],
]);
