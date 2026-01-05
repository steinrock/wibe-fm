<?php
// config.php
session_start();

// --- НАСТРОЙКИ ---
define('APP_USER', 'admin');
define('APP_PASS', 'admin'); 
define('STORAGE_DIR', __DIR__ . '/storage');
define('BASE_URL', '/wibe-fm'); // Важно для редиректов

if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

// --- АВТОРИЗАЦИЯ ---
function checkAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

// --- ХЕЛПЕРЫ ДЛЯ РАЗМЕРОВ ---
// Преобразует строковые значения php.ini (например, '500M') в байты
function parseSize($size) {
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    }
    return round($size);
}

// Определяет минимальный лимит из настроек PHP
function getMaxUploadSize() {
    $max_upload = parseSize(ini_get('upload_max_filesize'));
    $max_post = parseSize(ini_get('post_max_size'));
    return min($max_upload, $max_post);
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ... Остальные функции (getSafePath, getIcon) остаются без изменений ...
function getSafePath($path) {
    $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    $path = trim($path, DIRECTORY_SEPARATOR);
    $realBase = realpath(STORAGE_DIR);
    $target = $realBase . DIRECTORY_SEPARATOR . $path;
    $realTarget = realpath($target);
    if ($realTarget === false) {
        $realTarget = realpath(dirname($target)) . DIRECTORY_SEPARATOR . basename($target);
        if (strpos(realpath(dirname($target)), $realBase) !== 0) return false;
        return $target;
    }
    if ($realTarget && strpos($realTarget, $realBase) === 0) return $realTarget;
    return false;
}

function getIcon($filename, $isDir) {
    if ($isDir) return 'folder';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'svg' => 'image',
        'mp3' => 'audiotrack', 'wav' => 'audiotrack', 'ogg' => 'audiotrack',
        'mp4' => 'movie', 'webm' => 'movie', 'avi' => 'movie',
        'pdf' => 'picture_as_pdf', 'txt' => 'description', 'php' => 'code', 'html' => 'code',
        'zip' => 'archive', 'rar' => 'archive'
    ];
    return $icons[$ext] ?? 'insert_drive_file';
}
?>
