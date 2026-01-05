<?php
require_once 'config.php';
checkAuth();

header('Content-Type: application/json');

$name = trim($_POST['name'] ?? '');
$currentPath = $_POST['path'] ?? '';
$safePath = getSafePath($currentPath);

// ИСПРАВЛЕНИЕ: Используем blacklist (запрещаем только спецсимволы) вместо whitelist.
// Запрещаем: \ / : * ? " < > |
if (!$safePath || empty($name) || preg_match('/[\\/?*:;\"<>|]/u', $name)) {
    echo json_encode(['success' => false, 'error' => 'Недопустимое имя (запрещены символы \ / : * ? " < > |)']);
    exit;
}

$newDir = $safePath . DIRECTORY_SEPARATOR . $name;

if (file_exists($newDir)) {
    echo json_encode(['success' => false, 'error' => 'Папка уже существует']);
} else {
    if (mkdir($newDir, 0777)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Ошибка создания на сервере']);
    }
}
?>