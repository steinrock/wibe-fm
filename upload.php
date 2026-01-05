<?php
require_once 'config.php';
checkAuth();

// Настройки
$chunkDir = STORAGE_DIR . DIRECTORY_SEPARATOR . '.tmp_chunks';
if (!is_dir($chunkDir)) mkdir($chunkDir, 0777, true);

// --- 1. ПУТИ ---
$targetDirRel = isset($_POST['path']) ? $_POST['path'] : '';
$targetDirAbs = getSafePath($targetDirRel);

if (!$targetDirAbs) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid path']));
}

$relativePath = $_POST['relative_path'] ?? ''; 
$relativePath = str_replace(['..', '\\'], ['', '/'], $relativePath);
$relativePath = trim($relativePath, '/');

$finalDir = $targetDirAbs;
if (!empty($relativePath)) {
    $finalDir .= DIRECTORY_SEPARATOR . dirname($relativePath);
    if (!is_dir($finalDir)) mkdir($finalDir, 0777, true);
}

// --- 2. CHUNKING (DROPZONE) ---
if (isset($_POST['dzuuid'])) {
    // Используем ID, присланный клиентом (он теперь постоянный для файла)
    $uuid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['dzuuid']); 
    $chunkIndex = (int)$_POST['dzchunkindex'];
    $totalChunks = (int)$_POST['dztotalchunkcount'];
    $fileName = $_POST['dzfilename'] ?? $_FILES['file']['name'];
    
    $fileChunkDir = $chunkDir . DIRECTORY_SEPARATOR . $uuid;
    if (!is_dir($fileChunkDir)) mkdir($fileChunkDir, 0777, true);

    $chunkPath = $fileChunkDir . DIRECTORY_SEPARATOR . $chunkIndex;
    
    // === ЛОГИКА ДОКАЧКИ ===
    // Если файл уже есть и размер совпадает - НЕ ПИШЕМ ЕГО СНОВА
    $chunkSaved = false;
    if (file_exists($chunkPath) && filesize($chunkPath) == $_FILES['file']['size']) {
        $chunkSaved = true;
    } else {
        if (move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath)) {
            $chunkSaved = true;
        }
    }

    if (!$chunkSaved) {
        http_response_code(500);
        die(json_encode(['error' => 'Chunk write failed']));
    }

    // Проверка завершения (считаем файлы исключая . и ..)
    $chunksUploaded = count(scandir($fileChunkDir)) - 2; 
    
    if ($chunksUploaded >= $totalChunks) {
        // Сборка
        $targetFile = $finalDir . DIRECTORY_SEPARATOR . $fileName;
        $info = pathinfo($targetFile);
        $i = 1;
        while(file_exists($targetFile)) {
            $targetFile = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . "($i)." . $info['extension'];
            $i++;
        }

        $fp = fopen($targetFile, 'wb');
        for ($j = 0; $j < $totalChunks; $j++) {
            $chunkP = $fileChunkDir . DIRECTORY_SEPARATOR . $j;
            if (file_exists($chunkP)) {
                $chunkData = file_get_contents($chunkP);
                fwrite($fp, $chunkData);
                unlink($chunkP);
            }
        }
        fclose($fp);
        rmdir($fileChunkDir);
        echo json_encode(['status' => 'complete', 'file' => basename($targetFile)]);
    } else {
        echo json_encode(['status' => 'chunk_uploaded']);
    }
    exit;
}

// --- 3. ОБЫЧНАЯ ЗАГРУЗКА ---
if (!empty($_FILES['file'])) {
    $fileName = $_FILES['file']['name'];
    if(!empty($relativePath)) $fileName = basename($relativePath); 

    $targetFile = $finalDir . DIRECTORY_SEPARATOR . $fileName;
    $info = pathinfo($targetFile);
    $i = 1;
    while(file_exists($targetFile)) {
        $targetFile = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . "($i)." . $info['extension'];
        $i++;
    }
    
    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Move failed']);
    }
    exit;
}
?>