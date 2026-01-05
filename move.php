<?php
require_once 'config.php';
checkAuth();

header('Content-Type: application/json');

$sources = $_POST['sources'] ?? [];
$destination = $_POST['destination'] ?? '';

// Очистка пути назначения
$destPath = getSafePath($destination);

if (!$destPath || !is_dir($destPath)) {
    echo json_encode(['success' => false, 'error' => 'Целевая папка не найдена']);
    exit;
}

$errors = [];
$count = 0;

foreach ($sources as $sourceRel) {
    $sourceAbs = getSafePath($sourceRel);
    
    if (!$sourceAbs || !file_exists($sourceAbs)) {
        $errors[] = "Файл не найден: $sourceRel";
        continue;
    }

    $baseName = basename($sourceAbs);
    $targetAbs = $destPath . DIRECTORY_SEPARATOR . $baseName;

    // Защита от перемещения папки внутрь самой себя
    if (is_dir($sourceAbs) && strpos(realpath($targetAbs), realpath($sourceAbs)) === 0) {
        $errors[] = "Нельзя переместить папку саму в себя: $baseName";
        continue;
    }

    if (file_exists($targetAbs)) {
        $errors[] = "Файл уже существует: $baseName";
        continue;
    }

    if (rename($sourceAbs, $targetAbs)) {
        $count++;
    } else {
        $errors[] = "Ошибка перемещения: $baseName";
    }
}

echo json_encode([
    'success' => true, 
    'moved' => $count, 
    'errors' => $errors
]);