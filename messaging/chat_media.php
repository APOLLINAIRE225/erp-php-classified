<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

session_start();

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager','staff','employee','Patron','PDG','Directrice','Secretaire','Superviseur','informaticien']);

function media_fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function media_detect_mime(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $base = strtolower(basename($path));

    if ($ext === 'webm' || $ext === 'ogg') {
        if (str_starts_with($base, 'vocal_')) {
            return $ext === 'ogg' ? 'audio/ogg' : 'audio/webm';
        }
        if (str_starts_with($base, 'video_')) {
            return $ext === 'ogg' ? 'video/ogg' : 'video/webm';
        }
    }

    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'opus' => 'audio/opus',
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
        'mov' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];

    if (isset($map[$ext])) {
        return $map[$ext];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path) ?: '';
            finfo_close($finfo);
            if ($mime !== '') {
                return $mime;
            }
        }
    }

    return 'application/octet-stream';
}

$relative = trim((string) ($_GET['path'] ?? ''), '/');
if ($relative === '') {
    media_fail(400, 'Chemin média manquant');
}

if (str_contains($relative, "\0") || str_contains($relative, '..')) {
    media_fail(400, 'Chemin média invalide');
}

$fullPath = APP_ROOT . '/' . $relative;
$uploadsRoot = realpath(APP_ROOT . '/uploads');
$realPath = realpath($fullPath);

if ($uploadsRoot === false || $realPath === false || strncmp($realPath, $uploadsRoot, strlen($uploadsRoot)) !== 0) {
    media_fail(404, 'Média introuvable');
}

if (!is_file($realPath) || !is_readable($realPath)) {
    media_fail(404, 'Média indisponible');
}

$size = filesize($realPath);
$mime = media_detect_mime($realPath);
$filename = basename($realPath);
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . $mime);
header(
    'Content-Disposition: ' .
    ($isDownload ? 'attachment' : 'inline') .
    '; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
);

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
    $start = $matches[1] === '' ? 0 : (int) $matches[1];
    $end = $matches[2] === '' ? ($size - 1) : (int) $matches[2];

    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        media_fail(416, 'Plage invalide');
    }

    $end = min($end, $size - 1);
    $length = $end - $start + 1;

    http_response_code(206);
    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);

    $handle = fopen($realPath, 'rb');
    if ($handle === false) {
        media_fail(500, 'Ouverture média impossible');
    }

    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

header('Content-Length: ' . $size);
readfile($realPath);
exit;
