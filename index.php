<?php
require_once 'config.php';
checkAuth();
// --- GARBAGE COLLECTION (Очистка мусора) ---
// Запускается с вероятностью 2% при каждом запросе
if (rand(1, 50) === 1) {
    $tmpDir = sys_get_temp_dir();
    if (!is_writable($tmpDir)) $tmpDir = __DIR__;

    $files = scandir($tmpDir);
    $now = time();
    $ttl = 3600; // Время жизни мусора: 1 час (3600 сек)

    foreach ($files as $f) {
        // Ищем файлы, созданные нашим скриптом:
        // archive_*.zip (готовые архивы)
        // prog_*.json (файлы прогресса)
        // zip_* (временные файлы tempnam)
        if (
            (strpos($f, 'archive_') === 0 && substr($f, -4) === '.zip') ||
            (strpos($f, 'prog_') === 0 && substr($f, -5) === '.json') ||
            (strpos($f, 'zip_') === 0) 
        ) {
            $fullPath = $tmpDir . DIRECTORY_SEPARATOR . $f;
            // Если файл старше 1 часа - удаляем
            if (is_file($fullPath) && ($now - filemtime($fullPath) > $ttl)) {
                @unlink($fullPath);
            }
        }
    }
}
// Настройки окружения (UTF-8 + скрытие ошибок для чистого JSON/ZIP)
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- Backend Logic ---
$currentRelPath = isset($_GET['path']) ? trim($_GET['path'], '/') : '';
$currentAbsPath = getSafePath($currentRelPath);
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'asc';
if (!in_array($sort, ['name', 'size', 'date'])) $sort = 'name';
if (!in_array($order, ['asc', 'desc'])) $order = 'asc';

if (!$currentAbsPath || !file_exists($currentAbsPath)) {
    $currentRelPath = '';
    $currentAbsPath = STORAGE_DIR;
}

$files = [];
$isSearch = !empty($searchQuery);

// Сканирование
$scanFiles = function() use ($isSearch, $searchQuery, $currentAbsPath, $currentRelPath, $sort, $order) {
    $result = [];
    if ($isSearch) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STORAGE_DIR, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->getFilename()[0] === '.') continue;
            if (mb_stripos($file->getFilename(), $searchQuery) !== false) {
                $result[] = ['name' => $file->getFilename(), 'path' => substr($file->getPathname(), strlen(STORAGE_DIR) + 1), 'is_dir' => $file->isDir(), 'size' => $file->getSize(), 'date' => $file->getMTime()];
            }
        }
    } else {
        $scan = scandir($currentAbsPath);
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            if ($f[0] === '.') continue; 
            $fullP = $currentAbsPath . DIRECTORY_SEPARATOR . $f;
            $result[] = ['name' => $f, 'path' => ($currentRelPath ? $currentRelPath . '/' : '') . $f, 'is_dir' => is_dir($fullP), 'size' => filesize($fullP), 'date' => filemtime($fullP)];
        }
    }
    
    usort($result, function($a, $b) use ($sort, $order) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
        $cmp = 0;
        if ($sort === 'name') $cmp = strnatcasecmp($a['name'], $b['name']);
        elseif ($sort === 'size') $cmp = $a['size'] <=> $b['size'];
        elseif ($sort === 'date') $cmp = $a['date'] <=> $b['date'];
        return ($order === 'asc') ? $cmp : -$cmp;
    });
    return $result;
};

function getSortIcon($key, $curSort, $curOrder) {
    if ($curSort === $key) return ($curOrder === 'asc') ? 'arrow_drop_up' : 'arrow_drop_down';
    return '';
}

// ---------------- AJAX HANDLERS ----------------

// 1. Rename
if (isset($_POST['action']) && $_POST['action'] === 'rename_item') {
    header('Content-Type: application/json');
    $oldRel = $_POST['old_path'] ?? '';
    $newName = trim($_POST['new_name'] ?? '');
    
    if (!$oldRel || !$newName) die(json_encode(['success'=>false, 'error'=>'Неверные данные']));
    if (preg_match('/[\\/:\*\?"<>\|]/', $newName)) die(json_encode(['success'=>false, 'error'=>'Недопустимые символы']));

    $oldAbs = getSafePath($oldRel);
    if (!$oldAbs || !file_exists($oldAbs)) die(json_encode(['success'=>false, 'error'=>'Файл не найден']));

    $dir = dirname($oldAbs);
    $newAbs = $dir . DIRECTORY_SEPARATOR . $newName;

    if (file_exists($newAbs)) die(json_encode(['success'=>false, 'error'=>'Имя уже занято']));

    if (rename($oldAbs, $newAbs)) echo json_encode(['success'=>true]);
    else echo json_encode(['success'=>false, 'error'=>'Ошибка переименования']);
    exit;
}

// 2. Get Folders (Move)
if (isset($_GET['action']) && $_GET['action'] === 'get_folders') {
    $reqPath = isset($_GET['req_path']) ? trim($_GET['req_path'], '/') : '';
    $absPath = getSafePath($reqPath);
    $folders = [];
    if ($reqPath !== '') {
        $parent = dirname($reqPath) === '.' ? '' : dirname($reqPath);
        $folders[] = ['name' => '.. (Назад)', 'path' => $parent, 'is_back' => true];
    }
    if ($absPath && is_dir($absPath)) {
        $scan = scandir($absPath);
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            $fullP = $absPath . DIRECTORY_SEPARATOR . $f;
            if (is_dir($fullP)) $folders[] = ['name' => $f, 'path' => ($reqPath ? $reqPath . '/' : '') . $f, 'is_back' => false];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($folders);
    exit;
}

// 3. Disk Stats
if (isset($_GET['action']) && $_GET['action'] === 'get_disk_stats') {
    function getDirSize($dir) {
        $size = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) $size += $file->getSize();
        } catch (Exception $e) { return 0; }
        return $size;
    }
    $storageUsed = getDirSize(STORAGE_DIR);
    $diskFree = @disk_free_space(STORAGE_DIR);
    $diskTotal = @disk_total_space(STORAGE_DIR);
    header('Content-Type: application/json');
    echo json_encode(['used' => $storageUsed, 'free' => $diskFree ?: 0, 'total' => $diskTotal ?: 0]);
    exit;
}

// --- AJAX: Чтение прогресса (для JS polling) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_task_progress') {
    // Этот скрипт должен быть очень быстрым
    $pid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['pid'] ?? '');
    if (!$pid) die(json_encode(['progress' => 0]));

    $tmpDir = sys_get_temp_dir();
    if (!is_writable($tmpDir)) $tmpDir = __DIR__;
    
    $statusFile = $tmpDir . '/prog_' . $pid . '.json';
    
    if (file_exists($statusFile)) {
        // Читаем файл без блокировок
        $content = @file_get_contents($statusFile);
        echo $content ?: json_encode(['progress' => 0]);
    } else {
        echo json_encode(['progress' => 0]);
    }
    exit;
}

// ================= ZIP АРХИВАЦИЯ (FIXED) =================

// 1. AJAX: Чтение прогресса (для JS polling)
if (isset($_GET['action']) && $_GET['action'] === 'get_task_progress') {
    // Отключаем кеширование
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    
    $pid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['pid'] ?? '');
    if (!$pid) die(json_encode(['progress' => 0]));

    $tmpDir = sys_get_temp_dir();
    if (!is_writable($tmpDir)) $tmpDir = __DIR__;
    
    $statusFile = $tmpDir . '/prog_' . $pid . '.json';
    
    if (file_exists($statusFile)) {
        // Читаем файл
        $content = @file_get_contents($statusFile);
        echo $content ?: json_encode(['progress' => 0]);
    } else {
        echo json_encode(['progress' => 0]);
    }
    exit;
}

// 2. AJAX: Запуск создания архива (Фоновый процесс)
if (isset($_GET['action']) && $_GET['action'] === 'zip_create') {
    // Снимаем лимиты и закрываем сессию, чтобы не блокировать поллинг
    session_write_close(); 
    set_time_limit(0); 
    ini_set('memory_limit', '-1'); 
    ignore_user_abort(true); // Продолжаем работу даже при 504 Gateway Timeout
    
    header('Content-Type: application/json');

    // Функция записи статуса
    function writeStatus($file, $pct, $status, $error = null) {
        $data = ['progress' => $pct, 'status' => $status];
        if ($error) $data['error'] = $error;
        @file_put_contents($file, json_encode($data));
    }

    if (!class_exists('ZipArchive')) die(json_encode(['success'=>false, 'error'=>'Модуль PHP-ZIP не установлен']));

    $relPath = $_GET['path'] ?? '';
    $absPath = getSafePath($relPath);
    $pid = $_GET['pid'] ?? uniqid();

    // Пути к временным файлам
    $tmpDir = sys_get_temp_dir();
    if (!is_writable($tmpDir)) $tmpDir = __DIR__;
    
    $progressFile = $tmpDir . '/prog_' . preg_replace('/[^a-zA-Z0-9]/', '', $pid) . '.json';
    $zipFilename  = 'archive_' . preg_replace('/[^a-zA-Z0-9]/', '', $pid) . '.zip';
    $zipFullPath  = $tmpDir . '/' . $zipFilename;

    // Старт
    writeStatus($progressFile, 0, 'starting');

    if (!$absPath || !is_dir($absPath)) {
        writeStatus($progressFile, 0, 'error', 'Папка не найдена');
        exit;
    }

    // -- ЭТАП 1: Подсчет размера и файлов --
    $totalSize = 0;
    $totalFiles = 0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            $totalSize += $file->getSize();
            $totalFiles++;
        }
    } catch (Exception $e) { 
        writeStatus($progressFile, 0, 'error', 'Ошибка доступа к файлам');
        exit;
    }

    if ($totalFiles === 0) {
        writeStatus($progressFile, 0, 'error', 'Папка пуста');
        exit;
    }

    // -- ЭТАП 2: Проверка места (коэфф 1.1 для надежности) --
    $freeSpace = @disk_free_space($tmpDir);
    if ($freeSpace !== false && ($totalSize * 1.1) > $freeSpace) {
        $need = ($totalSize * 1.1) - $freeSpace;
        $msg = "Недостаточно места на сервере!\nРазмер архива: ~" . formatSize($totalSize) . "\nСвободно: " . formatSize($freeSpace) . "\nНужно освободить: " . formatSize($need);
        writeStatus($progressFile, 0, 'error', $msg);
        exit;
    }

    // -- ЭТАП 3: Архивация --
    $zip = new ZipArchive();
    if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        writeStatus($progressFile, 0, 'error', 'Не удалось создать ZIP файл');
        exit;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absPath, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    $processed = 0;
    $lastUpdate = 0;

    writeStatus($progressFile, 0, 'adding');

    foreach ($iterator as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($absPath) + 1);
            $zip->addFile($filePath, $relativePath);
            
            $processed++;
            
            // Пишем прогресс (0-90%)
            // Обновляем раз в 10 файлов или в конце
            if ($processed % 10 === 0 || $processed === $totalFiles) {
                $pct = intval(($processed / $totalFiles) * 90); 
                if ($pct > $lastUpdate) {
                    writeStatus($progressFile, $pct, 'adding');
                    $lastUpdate = $pct;
                }
            }
        }
    }

    // Сжатие (тяжелая операция, PHP тут висит)
    writeStatus($progressFile, 95, 'compressing');
    $zip->close();
    
    // Готово
    writeStatus($progressFile, 100, 'done');
    exit;
}

// 3. AJAX: Скачивание готового файла (Шаг 3)
if (isset($_GET['action']) && $_GET['action'] === 'zip_download') {
    // Очистка имени
    $file = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $_GET['file'] ?? '');
    $name = $_GET['name'] ?? 'archive';
    
    $tmpDir = sys_get_temp_dir();
    if (!is_writable($tmpDir)) $tmpDir = __DIR__;
    
    $filePath = $tmpDir . DIRECTORY_SEPARATOR . $file;

    if ($file && file_exists($filePath)) {
        // Очистка всех буферов вывода, чтобы архив не побился
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        
        readfile($filePath);
        
        // Удаляем архив после отправки
        unlink($filePath); 
        
        // Удаляем файл статуса
        if (preg_match('/archive_(.+)\.zip/', $file, $m)) {
            $p = $tmpDir . '/prog_' . $m[1] . '.json';
            if(file_exists($p)) unlink($p);
        }
        exit;
    }
    
    header("HTTP/1.0 404 Not Found");
    die('Error: File expired or not found.');
}

// 5. AJAX LIST (Table Rows)
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $files = $scanFiles();
    if (empty($files)) { echo '<tr><td colspan="5" class="center-align grey-text">'.($isSearch ? 'Ничего не найдено' : 'Папка пуста').'</td></tr>'; exit; }
    if (!$isSearch && $currentRelPath) { $parent = dirname($currentRelPath) === '.' ? '' : dirname($currentRelPath); echo '<tr class="back-row"><td colspan="5" class="name-cell"><a href="index.php?path='.urlencode($parent).'" class="black-text"><i class="material-icons file-icon">arrow_back</i>..</a></td></tr>'; }
    foreach ($files as $file) {
        $url = 'storage/' . $file['path'];
        $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $file['name']);
        
        echo '<tr class="file-item" data-type="'.($file['is_dir']?'dir':'file').'">
            <td class="chk-cell">
                <label><input type="checkbox" class="file-check" value="'.htmlspecialchars($file['path']).'"><span></span></label>
            </td>
            
            <td class="name-cell">';
                // Ссылка на файл/папку
                if ($file['is_dir']) {
                    echo '<a href="index.php?path='.urlencode($file['path']).'" class="black-text truncate-text file-link"><i class="material-icons file-icon folder-icon">folder</i>'.htmlspecialchars($file['name']).'</a>';
                } else {
                    echo '<a href="#" onclick="viewFile(\''.$url.'\',\''.htmlspecialchars($file['name'], ENT_QUOTES).'\',\''.($isImg?'img':'other').'\');return false;" class="black-text truncate-text file-link"><i class="material-icons file-icon">'.getIcon($file['name'],false).'</i>'.htmlspecialchars($file['name']).'</a>';
                }
                // Кнопка Rename (AJAX версия)
                echo '<i class="material-icons rename-btn" data-path="'.htmlspecialchars($file['path']).'" data-name="'.htmlspecialchars($file['name']).'" title="Переименовать">edit</i>';
    echo '</td>
            
            <td class="size-cell">'.($file['is_dir']?'-':formatSize($file['size'])).'</td>
            <td class="date-cell">'.date('d.m.Y H:i',$file['date']).'</td>
            
            <td class="action-cell">';
                // Кнопки действий
                if(!$file['is_dir']) {
                    echo '<a href="'.$url.'" download class="download-btn blue-text"><i class="material-icons">arrow_downward</i></a>';
                } else {
                    echo '<a href="index.php?action=zip_folder&path='.urlencode($file['path']).'" class="zip-btn amber-text zip-download-trigger" title="Скачать как ZIP"><i class="material-icons">archive</i></a>';
                }
                echo '<a href="#!" class="delete-btn red-text" onclick="deleteItem(\''.htmlspecialchars($file['path']).'\')"><i class="material-icons">delete</i></a>
            </td>
        </tr>';
    }
    exit;
}

// --- Main Page Render ---
$files = $scanFiles();
$maxUploadBytes = getMaxUploadSize();
$maxUploadHuman = formatSize($maxUploadBytes);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />
    <style>
        body { background: #f5f5f5; display: flex; flex-direction: column; min-height: 100vh; overflow-x: hidden; padding-bottom: 30px; }
        
        .sticky-top-wrapper { position: sticky; top: 0; z-index: 1000; background: #f5f5f5; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        nav .nav-wrapper { display: flex; align-items: center; }
        .search-container { width: 100%; max-width: 300px; margin-left: auto; margin-right: 20px; padding-left: 10px; padding-right: 10px; position: relative; background: rgba(255,255,255,0.15); border-radius: 4px; height: 44px; display: flex; align-items: center; transition: background 0.3s; }
        .search-container input { border-bottom: 2px!important; box-shadow: none!important; color: #fff; padding-left: 10px!important; margin: 0!important; height: 100%!important; width: 100%!important; box-sizing: border-box; }
        .file-icon { vertical-align: middle; margin-right: 10px; color: #607d8b; }
        .folder-icon { color: #ffb74d; }
        .breadcrumb-wrap { padding: 10px 0; background: #eee; margin-bottom: 0px; }
        .truncate-text { display: inline-block; max-width: 80%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sort-header { cursor: pointer; user-select: none; }
        .sort-header:hover { color: #26a69a; }
        .sort-icon { vertical-align: middle; margin-left: 5px; }

        /* Dropzone */
        .dropzone { border: 2px dashed #26a69a; border-radius: 5px; min-height: 200px; padding: 10px; background: #fafafa; }
        .dropzone .dz-preview { min-height: 170px !important; margin: 10px; }
        .dropzone .dz-preview .dz-details { top: 40% !important; padding-bottom: 0 !important; opacity: 1 !important; }
        .dropzone .dz-preview .dz-progress { opacity: 1 !important; z-index: 1000; position: absolute !important; top: auto !important; bottom: 40px !important; left: 50% !important; transform: translateX(-50%) !important; width: 80% !important; height: 8px !important; margin: 0 !important; background: rgba(0,0,0,0.05) !important; border-radius: 4px; border: none !important; }
        .dropzone .dz-preview .dz-progress .dz-upload { background: #26a69a !important; position: absolute; top: 0; left: 0; bottom: 0; width: 0; transition: width 0.3s linear; }
        .dropzone .dz-preview .dz-remove { position: absolute; bottom: 10px; left: 0; right: 0; text-align: center; z-index: 20; font-size: 12px; text-decoration: none; margin: 0 !important; cursor: pointer; }
        .dropzone .dz-preview .dz-remove:hover { text-decoration: underline; }

        /* Panel */
        /* Panel (Подняли выше на 50px, чтобы не наезжала на статистику) */
        #upload-panel { 
            position: fixed; 
            bottom: 50px; /* ИЗМЕНЕНО */
            right: 20px; 
            width: 320px; 
            background: white; 
            z-index: 2000; 
            border-radius: 8px; 
            display: none; 
            flex-direction: column; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
        }
        .panel-header { padding: 10px 15px; background: #26a69a; color: white; border-top-left-radius: 8px; border-top-right-radius: 8px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .panel-body { max-height: 300px; overflow-y: auto; padding: 0; flex-grow: 1; }
        .upload-item { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 12px; }
        .info-row { display: flex; justify-content: space-between; font-size: 10px; color: #999; margin-top: 2px; }
        
        #folder-progress-ui { margin-top: 20px; }
        .progress-label { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; color: #666; }
        .progress { height: 10px; margin: 0 0 15px 0; background-color: #e0f2f1; border-radius: 4px; }
        .progress .determinate { background-color: #26a69a; border-radius: 4px; transition: width 0.2s; }
        #current-file-name { font-size: 14px; font-weight: 500; color: #333; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tabs .tab a { color: #607d8b; } .tabs .tab a:hover, .tabs .tab a.active { color: #26a69a; } .tabs .indicator { background-color: #26a69a; }
        .inputs-blocked { pointer-events: none; opacity: 0.5; filter: grayscale(100%); }

        .actions-bar { display: flex; flex-wrap: wrap; gap: 10px; padding: 15px 0; align-items: center; }
        .actions-bar .btn { flex-grow: 1; min-width: 120px; }
        .select-all-container { margin-right: 0px; display: flex; align-items: center; background: #f5f5f5; padding: 0 0px; height: 36px; border-radius: 2px; }
        .select-all-container span { font-size: 13px; color: #333; margin-left: 5px; }
	/* Кнопка переименования */
        .rename-btn {
            cursor: pointer;
            color: #9e9e9e; /* Серый по умолчанию */
            margin-left: 10px;
            padding: 5px;
            border-radius: 50%;
            transition: background 0.3s, color 0.3s;
            user-select: none;
        }
        .rename-btn:hover {
            background: rgba(0,0,0,0.1);
            color: #2196f3; /* Синий при наведении */
        }
        /* Disk Footer */
        #disk-stat-footer { position: fixed; bottom: 0; left: 0; right: 0; height: 24px; background: rgba(255, 255, 255, 0.95); border-top: 1px solid #ccc; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; font-size: 11px; color: #666; z-index: 1900; box-shadow: 0 -2px 5px rgba(0,0,0,0.05); }
        #disk-stat-footer b { color: #333; }
/* --- PROGRESS BAR IN FOOTER --- */
        .footer-progress-container {
            position: absolute;
            top: 0; left: 0; bottom: 0; right: 0;
            background: #fff;
            display: none; /* Скрыт по умолчанию */
            align-items: center;
            padding: 0 20px;
            z-index: 2000;
        }
        .footer-progress-bar {
            flex-grow: 1;
            height: 6px;
            background: #e0f2f1;
            border-radius: 3px;
            margin: 0 10px;
            overflow: hidden;
        }
        .footer-progress-fill {
            height: 100%;
            background: #26a69a;
            width: 0%;
            transition: width 0.3s linear; /* Плавная анимация */
        }
        .footer-progress-text {
            font-size: 11px;
            color: #26a69a;
            font-weight: bold;
            min-width: 45px;
            text-align: right;
        }
		/* Анимация полос для прогресс-бара */
        @keyframes progress-stripes {
            from { background-position: 40px 0; }
            to { background-position: 0 0; }
        }
        .footer-progress-fill.active {
            background-image: linear-gradient(45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
            background-size: 40px 40px;
            animation: progress-stripes 1s linear infinite;
        }
        /* Mobile Layout */
        @media only screen and (max-width: 1359px) {
            .container { width: 96%; margin: 0 auto; }
            .brand-logo.left { display: none !important; }
            .search-container { max-width: none; margin: 0 10px; flex-grow: 1; }
            table.responsive-table, thead, tbody, th, td, tr { display: block !important; width: 100% !important; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            
            tr.file-item { position: relative; margin-bottom: 15px; background: #fff; border-radius: 4px; border: 1px solid #ccc !important; border-bottom: 1px solid #ccc !important; height: 100px; padding: 0; overflow: hidden; }
            td { padding: 0 !important; border: none !important; display: block; height: 0; }

            td.chk-cell { position: absolute; top: 0; left: 0; width: 50px; height: 50px; display: flex; justify-content: center; align-items: center; z-index: 20; border-bottom: 2px solid #ccc !important; }
            [type="checkbox"] + span:not(.lever) { height: 5; line-height: 24px; padding-left: 30px; margin-top: 10px; margin-left: 15px; }

	    /* Делаем ячейку имени флекс-контейнером, чтобы разнести имя и карандаш */
            td.name-cell {
                position: absolute; top: 0px; left: 50px; right: 0; height: 50px;
                display: flex !important; align-items: center; justify-content: flex-start;
                padding: 0 15px 0 10px !important; /* Отступ справа для кнопки */
                border-bottom: 2px solid #ccc !important; box-sizing: border-box;
            }
	    td.name-cell a.file-link {
                color: #000 !important; font-size: 15px; font-weight: 500;
                display: block; flex-grow: 0;
                max-width: calc(100% - 50px); /* Разрешает сжатие текста */
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                margin-right: 10px;
            }
            /* Карандаш всегда виден и покрупнее */
	    .rename-btn {
		display: inline-block !important; 
                opacity: 1 !important; 
                color: #546e7a !important;
                font-size: 24px !important; 
                padding: 10px !important; 
                margin: 0 !important;
                flex-shrink: 0; 
                position: relative; 
                z-index: 999; /* Максимальный приоритет клика */
                cursor: pointer;
            }
            .file-icon { margin-top: -5px; margin-right: 8px; font-size: 20px; flex-shrink: 0; }
            
            .download-btn, .zip-btn { position: absolute; top: 50px; left: 0; width: 50px; height: 50px; display: flex; justify-content: center; align-items: center; z-index: 30; border-right: 1px solid #ccc; }
            .download-btn i { font-size: 32px; color: #039be5 !important; font-weight: bold; }
            .zip-btn i { font-size: 32px; color: #ffab00 !important; font-weight: bold; }
            
            .delete-btn { position: absolute; top: 50px; right: 0; width: 50px; height: 50px; display: flex; justify-content: center; align-items: center; z-index: 30; border-left: 1px solid #ccc; }
            .delete-btn i { font-size: 32px; color: #f44336 !important; }

            td.size-cell { position: absolute; top: 55px; left: 65px; right: 65px; height: 20px; font-weight: bold; font-size: 13px; color: #333; text-align: left; white-space: nowrap; border: none !important; padding: 0 !important; z-index: 15; }
            td.date-cell { position: absolute; top: 75px; left: 65px; right: 65px; height: 20px; font-weight: regular; font-size: 13px; color: #333; text-align: left; white-space: nowrap; border: none !important; padding: 0 !important; z-index: 15; }
            td.action-cell { display: none; }
    
            tr.back-row { height: 50px !important; padding: 0; border: 1px solid #ccc !important; border-bottom: 1px solid #ccc !important; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: auto; margin-bottom: 15px; border-radius: 4px; }
            tr.back-row td.name-cell { position: static; border: none !important; height: auto; font-weight: bold; background: transparent; width: 100%; text-align: center; padding: 13px !important; display: block !important; }
            tr.back-row td.name-cell a { justify-content: center; margin: 0; }
            #disk-stat-footer { padding: 0 10px; font-size: 10px; }
        }
        	@media only screen and (max-width: 600px) {
           		#disk-stat-footer { padding: 0 10px; font-size: 10px; }
		}
    </style>
</head>
<body>

<div class="sticky-top-wrapper">
    <nav class="blue-grey darken-3">
        <div class="nav-wrapper container">
            <a href="index.php" class="brand-logo left hide-on-med-and-down">Storage</a>
            <div class="search-container">
                <i class="material-icons">search</i>
                <input id="dynamic-search" type="text" placeholder="Поиск..." value="<?= htmlspecialchars($searchQuery) ?>">
                <i class="material-icons" onclick="clearSearch()">close</i>
            </div>
            <ul class="right"><li><a href="logout.php"><i class="material-icons">exit_to_app</i></a></li></ul>
        </div>
    </nav>

    <div class="breadcrumb-wrap">
        <div class="container" id="breadcrumb-container">
            <a href="index.php" class="breadcrumb black-text">Главная</a>
            <?php if ($currentRelPath): $parts = array_filter(explode('/', $currentRelPath)); $cp=''; foreach ($parts as $p): $cp.=($cp?'/':'').$p; ?>
                <a href="index.php?path=<?= urlencode($cp) ?>" class="breadcrumb black-text"><?= htmlspecialchars($p) ?></a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="container">
        <div class="row" style="margin-top: 0; margin-left: -10px;"> 
            <div class="col s12">
                <div class="actions-bar"">
                    <div class="select-all-container" title="Выбрать все">
                        <label>
                            <input type="checkbox" class="select-all-trigger" />
                            <span></span>
                        </label>
                    </div>
                    <a class="waves-effect waves-light btn blue modal-trigger" href="#modal-upload"><i class="material-icons left">cloud_upload</i>Загрузить</a>
                    <a class="waves-effect waves-light btn green modal-trigger" href="#modal-new-folder" id="btn-create-modal"><i class="material-icons left">create_new_folder</i>Папка</a>
                    <button id="btn-delete-multi" class="waves-effect waves-light btn red disabled"><i class="material-icons left">delete</i>Удалить</button>
                    <button id="btn-move-multi" class="waves-effect waves-light btn orange disabled"><i class="material-icons left">low_priority</i>Переместить</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container" style="padding-top: 20px;">
    <table class="highlight responsive-table" id="file-table">
        <thead class="hide-on-small-only">
            <tr>
                <th width="50"><label><span></span></label></th>
                <th width="65%" class="sort-header" data-sort="name" onclick="handleSort('name')">Имя <i class="material-icons tiny sort-icon"><?= getSortIcon('name', $sort, $order) ?></i></th>
                <th width="15%" class="sort-header" data-sort="size" onclick="handleSort('size')">Размер <i class="material-icons tiny sort-icon"><?= getSortIcon('size', $sort, $order) ?></i></th>
                <th width="20%"class="sort-header" data-sort="date" onclick="handleSort('date')">Дата <i class="material-icons tiny sort-icon"><?= getSortIcon('date', $sort, $order) ?></i></th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody id="file-list-body">
        <?php if (!$isSearch && $currentRelPath): ?>
            <tr class="back-row"><td colspan="5" class="name-cell"><a href="index.php?path=<?= urlencode(dirname($currentRelPath) === '.' ? '' : dirname($currentRelPath)) ?>" class="black-text"><i class="material-icons file-icon">arrow_back</i>..</a></td></tr>
        <?php endif; ?>
        <?php if(empty($files)): ?>
             <tr><td colspan="5" class="center-align grey-text">Папка пуста</td></tr>
        <?php else: ?>
            <?php foreach ($files as $file): 
                $url = 'storage/' . $file['path'];
                $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $file['name']);
            ?>
            <tr class="file-item" data-type="<?= $file['is_dir'] ? 'dir' : 'file' ?>">
                <td class="chk-cell"><label><input type="checkbox" class="file-check" value="<?= htmlspecialchars($file['path']) ?>"><span></span></label></td>
                
                <td class="name-cell">
                    <?php if ($file['is_dir']): ?>
                        <a href="index.php?path=<?= urlencode($file['path']) ?>" class="black-text truncate-text file-link"><i class="material-icons file-icon folder-icon">folder</i><?= htmlspecialchars($file['name']) ?></a>
                    <?php else: ?>
                        <a href="#" onclick="viewFile('<?= $url ?>','<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>','<?= ($isImg?'img':'other') ?>');return false;" class="black-text truncate-text file-link"><i class="material-icons file-icon"><?= getIcon($file['name'],false) ?></i><?= htmlspecialchars($file['name']) ?></a>
                    <?php endif; ?>
                    
                    <i class="material-icons rename-btn" 
                       data-path="<?= htmlspecialchars($file['path']) ?>" 
                       data-name="<?= htmlspecialchars($file['name']) ?>"
                       title="Переименовать">edit</i>
                </td>
                                    
                <td class="size-cell"><?= $file['is_dir']?'-':formatSize($file['size']) ?></td>
                <td class="date-cell"><?= date('d.m.Y H:i',$file['date']) ?></td>
                
                <td class="action-cell">
                    <?php if(!$file['is_dir']): ?>
                        <a href="<?= $url ?>" download class="download-btn blue-text"><i class="material-icons">arrow_downward</i></a>
                    <?php else: ?>
                        <a href="index.php?action=zip_folder&path=<?= urlencode($file['path']) ?>" class="zip-btn amber-text zip-download-trigger" title="Скачать как ZIP"><i class="material-icons">archive</i></a>
                    <?php endif; ?>
                    <a href="#!" class="delete-btn red-text" onclick="deleteItem('<?= htmlspecialchars($file['path']) ?>')"><i class="material-icons">delete</i></a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="modal-upload" class="modal modal-fixed-footer" style="max-height: 85%;">
    <div class="modal-content">
        <h4>Загрузка</h4>
        <div class="row">
            <div class="col s12">
                <ul class="tabs">
                    <li class="tab col s6"><a class="active" href="#tab-files">Отдельные файлы</a></li>
                    <li class="tab col s6"><a href="#tab-folder">Папка целиком</a></li>
                </ul>
            </div>
            
            <div id="upload-inputs-container">
                <div id="tab-files" class="col s12" style="padding-top: 20px;">
                    <p>Перетащите файлы сюда (Drag & Drop):</p>
                    <form action="upload.php" class="dropzone" id="myDropzone">
                        <input type="hidden" name="path" value="<?= htmlspecialchars($currentRelPath) ?>">
                    </form>
                </div>
                <div id="tab-folder" class="col s12" style="padding-top: 20px;">
                    <p>Выберите папку для рекурсивной загрузки:</p>
                    <div class="file-field input-field">
                        <div class="btn grey lighten-2 black-text" style="height:36px; line-height:36px; padding:0 15px"><span>Выбрать папку</span><input type="file" id="folderInput" webkitdirectory directory multiple></div>
                        <div class="file-path-wrapper"><input class="file-path validate" type="text" placeholder="Папка не выбрана"></div>
                    </div>
                    <div id="folder-progress-ui" class="hide">
                        <div id="current-file-name" class="truncate">Подготовка...</div>
                        <div class="progress-label"><span>Текущий файл</span><span id="file-percent">0%</span></div>
                        <div class="progress"><div class="determinate" id="bar-file" style="width: 0%"></div></div>
                        <div class="progress-label"><span>Общий прогресс</span><span id="total-percent">0%</span></div>
                        <div class="progress"><div class="determinate" id="bar-total" style="width: 0%"></div></div>
                        <small class="grey-text" id="status-text">Ожидание...</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <span id="upload-summary-text" class="grey-text left" style="margin: 10px; font-size: 13px;"></span>
        <a href="#!" id="btn-stop" class="waves-effect waves-light btn grey darken-1 hide" style="margin-right: 10px;">Остановить</a>
        <a href="#!" id="btn-action" class="waves-effect waves-light btn blue disabled">Начать загрузку</a>
    </div>
</div>

<div id="upload-panel" class="z-depth-3">
    <div class="panel-header"><span>Загрузка...</span><i class="material-icons">keyboard_arrow_down</i></div>
    <div class="panel-body" id="upload-list"></div>
</div>

<div id="modal-new-folder" class="modal">
    <div class="modal-content"><h4>Новая папка</h4><input id="new_folder_name" type="text"></div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Отмена</a>
        <a href="#!" id="btn-create-folder" class="waves-effect btn green">Создать</a>
    </div>
</div>

<div id="modal-rename" class="modal">
    <div class="modal-content">
        <h4>Переименовать</h4>
        <p>Введите новое имя для: <b id="rename-target-name"></b></p>
        <div class="input-field">
            <input id="rename-new-input" type="text">
            <label for="rename-new-input" class="active">Новое имя</label>
        </div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Отмена</a>
        <a href="#!" id="btn-confirm-rename" class="waves-effect waves-light btn blue">Сохранить</a>
    </div>
</div>

<div id="modal-move" class="modal modal-fixed-footer">
    <div class="modal-content">
        <h4>Переместить в...</h4>
        <div class="card-panel grey lighten-4" style="padding: 10px;">
            <i class="material-icons tiny">folder_open</i> Текущая цель: 
            <strong id="move-target-label">/</strong>
        </div>
        <div class="collection" id="move-folder-list" style="border: none;"></div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Отмена</a>
        <a href="#!" id="btn-confirm-move" class="waves-effect waves-light btn orange">Переместить сюда</a>
    </div>
</div>

<div id="modal-preview" class="modal"><div class="modal-content center-align" id="preview-content"></div><div class="modal-footer"><a href="#!" class="modal-close btn-flat">Закрыть</a></div></div>

<div id="disk-stat-footer">
    <div id="disk-stat-content" style="display:flex; justify-content:space-between; width:100%">
        <span>Занято (Storage): <b id="stat-used">...</b></span>
        <span>Свободно на диске: <b id="stat-free">...</b></span>
    </div>
    
    <div id="footer-operation-progress" class="footer-progress-container">
        <span id="footer-op-name">Архивация:</span>
        <div class="footer-progress-bar">
            <div class="footer-progress-fill" id="footer-op-bar"></div>
        </div>
        <span class="footer-progress-text" id="footer-op-pct">0%</span>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>
<script>
    Dropzone.autoDiscover = false;
    const currentPath = "<?= htmlspecialchars($currentRelPath) ?>";
    const MAX_MB = <?= round($maxUploadBytes/1048576) ?>;
    const CHUNK_SIZE = 2000000; // 2MB

    let uploadState = 'idle'; 
    let modalInst = null;
    let tabsInst = null;
    let myDropzone = null;
    
    let globalQueue = []; 
	let serverFreeBytes = 0;
    let globalTotalBytes = 0; 
    let globalLoadedBytes = 0;
    
    let currentFileIndex = 0;
    let currentXhr = null; 
    
    let currentSort = "<?= htmlspecialchars($sort) ?>";
    let currentOrder = "<?= htmlspecialchars($order) ?>";

    $(document).ready(function(){
        modalInst = M.Modal.init(document.getElementById('modal-upload'), { dismissible: true, onCloseEnd: function() {
            if (uploadState === 'uploading' || uploadState === 'paused') { $('#upload-panel').show(); M.toast({html: 'Загрузка свернута', classes: 'rounded'}); }
        }});
        tabsInst = M.Tabs.init(document.querySelector('.tabs'), { onShow: function() { updateButtonState(); } });
        $('.modal').modal();
        updateSortIcons();

        // --- CHECKBOX LOGIC (SHIFT SELECT) ---
        let lastCheckedIndex = null;
        $(document).on('click', '.file-check', function(e) {
            let checkboxes = $('.file-check');
            let currentIndex = checkboxes.index(this);
            if (e.shiftKey && lastCheckedIndex !== null) {
                let start = Math.min(lastCheckedIndex, currentIndex);
                let end = Math.max(lastCheckedIndex, currentIndex);
                let isChecked = $(this).prop('checked');
                for (let i = start; i <= end; i++) checkboxes.eq(i).prop('checked', isChecked);
            }
            lastCheckedIndex = currentIndex;
            updateActionButtons();
        });
        
        $(document).on('change', '.select-all-trigger', function() { 
            let isChecked = $(this).prop('checked');
            $('.select-all-trigger').prop('checked', isChecked);
            $('.file-check').prop('checked', isChecked); 
            updateActionButtons();
        });

        function updateActionButtons() {
            let count = $('.file-check:checked').length;
            if (count > 0) {
                $('#btn-delete-multi').removeClass('disabled');
                $('#btn-move-multi').removeClass('disabled');
            } else {
                $('#btn-delete-multi').addClass('disabled');
                $('#btn-move-multi').addClass('disabled');
            }
        }

        // --- MOBILE CARD CLICK ---
        $(document).on('click', 'tr.file-item', function(e) {
            if (window.innerWidth <= 1359) {
                if ($(e.target).closest('.file-check, label, .delete-btn, .download-btn, .zip-btn, .rename-btn').length) return;
                let link = $(this).find('.file-link');
                if(link.length) {
                    if (link.attr('onclick')) link.click(); else window.location.href = link.attr('href');
                }
            }
        });

        // --- DROPZONE SETUP ---
        myDropzone = new Dropzone("#myDropzone", {
            paramName: "file", maxFilesize: MAX_MB, autoProcessQueue: false, parallelUploads: 100, addRemoveLinks: true,
            init: function() {
                let dz = this;
                this.on("addedfile", function(file) { setTimeout(() => updateButtonState(), 10); file.uid = 'dz-' + Math.random().toString(36).substr(2, 9); file.customChunkIdx = 0; addMiniPanelItem(file.uid, file.name); });
                this.on("removedfile", function(file) { setTimeout(() => updateButtonState(), 10); if(file.uid) removeMiniPanelItem(file.uid); });
            }
        });

        $('#folderInput').change(function(e) { 
            let files = Array.from(e.target.files);
            files.forEach(f => { f.uid = 'fld-' + Math.random().toString(36).substr(2, 9); f.customChunkIdx = 0; });
            globalQueue = files; updateButtonState(); 
        });
        
        // --- BUTTONS ---
        $('#btn-action').click(function() {
            if($(this).hasClass('disabled')) return;
            if (uploadState === 'idle') startUpload();
            else if (uploadState === 'uploading') pauseUpload();
            else if (uploadState === 'paused') resumeUpload();
            else if (uploadState === 'finished') finishAndReset();
        });
        $('#btn-stop').click(function() { stopUpload(); });
        $('#btn-create-folder').click(function(e) { e.preventDefault(); createFolder(); });
        
        // Panel Toggle
        $('.panel-header').off('click').on('click', function(e){
             e.stopPropagation(); 
             let icon = $(this).find('i');
             let body = $('.panel-body');
             body.slideToggle(200, function() {
                 if (body.is(':visible')) icon.text('keyboard_arrow_down'); else icon.text('keyboard_arrow_up');
             });
        });

        $('#btn-delete-multi').click(function(){ if(confirm('Удалить?')) $.post('delete.php', {paths:$('.file-check:checked').map((_,e)=>e.value).get()}, function(){ performSearch(''); updateDiskStats(); }); });
        
        // === RENAME ===
        let currentRenamePath = '';
        let modalRename = M.Modal.init(document.getElementById('modal-rename'), { onOpenStart: function() { $('#rename-new-input').focus(); } });

        $(document).on('click', '.rename-btn', function(e) {
            e.preventDefault(); e.stopPropagation();
            let path = $(this).data('path');
            let name = $(this).data('name');
            currentRenamePath = path;
            $('#rename-target-name').text(name);
            $('#rename-new-input').val(name);
            M.updateTextFields();
            modalRename.open();
        });

        $('#rename-new-input').on('keypress', function(e) { if(e.which === 13) $('#btn-confirm-rename').click(); });

        $('#btn-confirm-rename').click(function() {
            let newName = $.trim($('#rename-new-input').val());
            if (!newName) { M.toast({html: 'Введите имя', classes: 'red'}); return; }
            $.post('index.php', { action: 'rename_item', old_path: currentRenamePath, new_name: newName }, function(res) {
                if (res.success) { M.toast({html: 'Переименовано', classes: 'green'}); modalRename.close(); performSearch(''); } 
                else { M.toast({html: res.error, classes: 'red'}); }
            }, 'json').fail(function() { M.toast({html: 'Ошибка сервера', classes: 'red'}); });
        });

        // === MOVE ===
        let currentMovePath = '';
        let modalMove = M.Modal.init(document.getElementById('modal-move'));

        $('#btn-move-multi').click(function() {
            if($(this).hasClass('disabled')) return;
            currentMovePath = ''; loadMoveFolders(''); modalMove.open();
        });

        function loadMoveFolders(path) {
            currentMovePath = path;
            $('#move-target-label').text(path === '' ? 'Главная' : path);
            $('#move-folder-list').html('<div class="center-align"><div class="preloader-wrapper small active"><div class="spinner-layer spinner-blue-only"><div class="circle-clipper left"><div class="circle"></div></div></div></div></div>');
            $.get('index.php', { action: 'get_folders', req_path: path }, function(data) {
                let html = '';
                if (data.length === 0) html = '<div class="center-align grey-text" style="padding:20px">Нет подпапок</div>';
                else {
                    data.forEach(f => {
                        let icon = f.is_back ? 'arrow_back' : 'folder';
                        html += `<a href="#!" class="collection-item black-text move-folder-item" data-path="${f.path}"><i class="material-icons left">${icon}</i> ${f.name}</a>`;
                    });
                }
                $('#move-folder-list').html(html);
            }, 'json');
        }

        $(document).on('click', '.move-folder-item', function(e) { e.preventDefault(); let path = $(this).data('path'); loadMoveFolders(path); });

        $('#btn-confirm-move').click(function() {
            let sources = $('.file-check:checked').map((_, el) => el.value).get();
            if (sources.length === 0) return;
            let btn = $(this); btn.addClass('disabled').text('Перемещение...');
            $.post('move.php', { sources: sources, destination: currentMovePath }, function(res) {
                btn.removeClass('disabled').text('Переместить сюда');
                if (res.success) { M.toast({html: `Перемещено: ${res.moved}`, classes: 'green'}); if (res.errors.length > 0) alert('Ошибки:\n' + res.errors.join('\n')); modalMove.close(); performSearch(''); } 
                else { M.toast({html: res.error, classes: 'red'}); }
            }, 'json').fail(function() { btn.removeClass('disabled').text('Переместить сюда'); M.toast({html: 'Ошибка сервера', classes: 'red'}); });
			performSearch(''); // Это обновит таблицу и вызовет resetSelection()
            resetSelection();  // На всякий случай сбрасываем визуально сразу
        });
// === ZIP DOWNLOAD: Исправленная версия ===
        $(document).on('click', '.zip-download-trigger', function(e) {
            e.preventDefault();
            
            let btn = $(this);
            if(btn.hasClass('disabled')) return;
            
            let originalIcon = btn.find('i').text();
            btn.addClass('disabled').find('i').text('hourglass_empty');
            
            let urlObj = new URL(this.href);
            let path = urlObj.searchParams.get('path');
            let pid = 'zip' + Date.now(); 
            
            // Формируем ссылку на скачивание заранее
            let downloadUrl = 'index.php?action=zip_download&file=archive_' + pid + '.zip&name=' + encodeURIComponent(path.split('/').pop()) + '.zip';

            // UI
            $('#disk-stat-content').hide();
            $('#footer-operation-progress').css('display', 'flex');
            $('#footer-op-name').text('Подготовка...');
            $('#footer-op-pct').text('0%');
            $('#footer-op-bar').css('width', '0%').addClass('active');
            
            // 1. Запуск создания (Fire & Forget)
            // Мы не ждем ответа, так как при больших файлах может быть timeout
            fetch('index.php?action=zip_create&path=' + encodeURIComponent(path) + '&pid=' + pid).catch(e => console.log('Request started'));

            // 2. Поллинг (Следим за файлом)
            let fakeProgress = 10;
            let errorCount = 0;
            
            let pollInterval = setInterval(function() {
                $.ajax({
                    url: 'index.php',
                    data: { action: 'get_task_progress', pid: pid },
                    dataType: 'json',
                    cache: false,
                    success: function(data) {
                        errorCount = 0;
                        let pct = parseInt(data.progress);
                        let status = data.status;

                        // ОШИБКА НА СЕРВЕРЕ
                        if (status === 'error') {
                            clearInterval(pollInterval);
                            alert('Ошибка архивации: ' + (data.error || 'Неизвестно'));
                            resetFooter();
                            return;
                        }

                        // ГОТОВО -> СКАЧИВАЕМ
                        if (status === 'done' || pct >= 100) {
                            clearInterval(pollInterval);
                            $('#footer-op-bar').css('width', '100%');
                            $('#footer-op-pct').text('100%');
                            $('#footer-op-name').text('Скачивание...');
                            
                            // ГЛАВНОЕ ИЗМЕНЕНИЕ: Прямой переход на файл
                            window.location.href = downloadUrl;
                            
                            setTimeout(resetFooter, 4000); // Сбрасываем футер через 4 сек
                            return;
                        }

                        // АНИМАЦИЯ
                        if (!isNaN(pct)) {
                            if (status === 'compressing' && pct >= 90) {
                                $('#footer-op-name').text('Сжатие...');
                                if (fakeProgress < 99) fakeProgress += 0.2;
                                pct = fakeProgress;
                            } else if (status === 'adding') {
                                $('#footer-op-name').text('Архивация...');
                            } else {
                                $('#footer-op-name').text('Обработка...');
                            }

                            $('#footer-op-pct').text(Math.floor(pct) + '%');
                            $('#footer-op-bar').css('width', pct + '%');
                        }
                    },
                    error: function() {
                        errorCount++;
                        if(errorCount > 30) { // 15 секунд нет связи
                            clearInterval(pollInterval);
                            alert('Связь с сервером потеряна');
                            resetFooter();
                        }
                    }
                });
            }, 500);

            function resetFooter() {
                if(pollInterval) clearInterval(pollInterval);
                $('#footer-operation-progress').hide();
                $('#disk-stat-content').show();
                $('.zip-download-trigger').removeClass('disabled');
                btn.find('i').text(originalIcon);
                $('#footer-op-bar').removeClass('active');
                updateDiskStats();
            }
        });
        let st;
        $('#dynamic-search').on('input', function() { clearTimeout(st); st = setTimeout(() => performSearch($(this).val()), 300); });
        
        // --- DISK STATS ---
		function updateDiskStats() {
			$.get('index.php', { action: 'get_disk_stats' }, function(res) {
				serverFreeBytes = res.free; // Сохраняем для проверок
				$('#stat-used').text(formatFileSize(res.used));
				$('#stat-free').text(formatFileSize(res.free));
		}, 'json');
    }
        updateDiskStats();
    });

    // --- UPLOAD LOGIC ---
function startUpload() {
        let tab = getActiveTabId();
        // ... (код сбора globalQueue такой же) ...
        
        if (tab === '#tab-files') {
             globalQueue = myDropzone.getFilesWithStatus(Dropzone.QUEUED);
             if (globalQueue.length === 0) globalQueue = myDropzone.getAcceptedFiles();
        } else if (tab === '#tab-folder') {
             globalQueue = Array.from($('#folderInput')[0].files);
        }

        if (globalQueue.length === 0) return;

        // --- ПРОВЕРКА МЕСТА (НОВОЕ) ---
        let totalUploadSize = 0;
        globalQueue.forEach(f => totalUploadSize += f.size);

        if (totalUploadSize > serverFreeBytes) {
            let needed = totalUploadSize - serverFreeBytes;
            alert(`ОШИБКА: Недостаточно места на сервере!\n\nРазмер загрузки: ${formatFileSize(totalUploadSize)}\nСвободно: ${formatFileSize(serverFreeBytes)}\n\nВам нужно освободить еще: ${formatFileSize(needed)}`);
            return; // Прерываем операцию
        }
        // ------------------------------

        uploadState = 'uploading'; lockInputs(true); setButtonState('uploading'); $('#folder-progress-ui').removeClass('hide');
        globalTotalBytes = 0; globalLoadedBytes = 0;
        globalQueue.forEach(f => { globalTotalBytes += f.size; f.customChunkIdx = 0; if(!f.uid) f.uid = 'u-' + Math.random().toString(36).substr(2, 9); if ($('#mp-' + f.uid).length === 0) addMiniPanelItem(f.uid, f.name); });
        currentFileIndex = 0; processNextFile();
    }

    function pauseUpload() { uploadState = 'paused'; setButtonState('paused'); if (currentXhr) currentXhr.abort(); M.toast({html: 'Загрузка приостановлена', classes: 'orange'}); }
    function resumeUpload() { uploadState = 'uploading'; setButtonState('uploading'); processNextFile(); M.toast({html: 'Загрузка возобновлена', classes: 'green'}); }
	function stopUpload() { 
        uploadState = 'idle'; 
        if (currentXhr) currentXhr.abort(); 
        
        // Очистка Dropzone
        myDropzone.removeAllFiles(true); 
        
        // Сброс переменных
        globalQueue = []; 
        $('#folderInput').val(''); 
        $('.file-path').val(''); 
        $('#folder-progress-ui').addClass('hide'); 
        $('#upload-summary-text').text(''); 
        
        // Очистка и скрытие панели загрузки (ДОБАВЛЕНО)
        $('#upload-list').html(''); 
        $('#upload-panel').fadeOut(); // Плавное скрытие
        
        lockInputs(false); 
        setButtonState('idle'); 
        updateButtonState(); 
        
        M.toast({html: 'Загрузка остановлена', classes: 'red'}); 
    }

    function processNextFile() {
        if (uploadState === 'paused' || uploadState === 'idle') return;
        if (currentFileIndex >= globalQueue.length) { checkAllFinished(); return; }
        let file = globalQueue[currentFileIndex]; let relPath = file.webkitRelativePath || file.name;
        $('#current-file-name').text(relPath); $(`#mp-${file.uid} .pct-text`).text('0%').css('color', '#666');
        let totalChunks = Math.ceil(file.size / CHUNK_SIZE); let chunkIdx = file.customChunkIdx || 0;
        if (chunkIdx > 0) { let loadedGuess = chunkIdx * CHUNK_SIZE; let pctGuess = (chunkIdx / totalChunks) * 100; updateMiniPanelItem(file.uid, pctGuess, loadedGuess, file.size, "Возобновление..."); }
        file.startTime = Date.now(); file.startLoaded = (file.customChunkIdx || 0) * CHUNK_SIZE;
        uploadFileChunk(file, relPath);
    }

    function uploadFileChunk(file, relPath) {
        if (uploadState !== 'uploading') return;
        let totalChunks = Math.ceil(file.size / CHUNK_SIZE); let chunkIdx = file.customChunkIdx || 0;
        let start = chunkIdx * CHUNK_SIZE; let end = Math.min(start + CHUNK_SIZE, file.size);
        let blob = file.slice(start, end);
        let fd = new FormData();
        fd.append('file', blob, file.name); fd.append('dzuuid', file.uid); fd.append('dzchunkindex', chunkIdx); fd.append('dztotalchunkcount', totalChunks); fd.append('dzchunksize', CHUNK_SIZE); fd.append('dztotalfilesize', file.size); fd.append('dzfilename', file.name); fd.append('path', currentPath); fd.append('relative_path', relPath);
        let chunkStartTime = Date.now();
        currentXhr = $.ajax({
            url: 'upload.php', type: 'POST', data: fd, contentType: false, processData: false,
            success: function(response) {
                globalLoadedBytes += blob.size;
                let now = Date.now(); let timeDiff = (now - chunkStartTime) / 1000; let speedBytes = 0; let speedText = "";
                if (timeDiff > 0) { speedBytes = blob.size / timeDiff; speedText = formatFileSize(speedBytes) + '/s'; }
                let filePercent = ((chunkIdx + 1) / totalChunks) * 100; if(filePercent > 100) filePercent = 100;
                let currentLoaded = Math.min((chunkIdx + 1) * CHUNK_SIZE, file.size);
                $('#bar-file').css('width', filePercent + '%'); $('#file-percent').text(Math.round(filePercent) + '%');
                updateMiniPanelItem(file.uid, filePercent, currentLoaded, file.size, speedText);
                let totalPct = (globalLoadedBytes / globalTotalBytes) * 100; if(totalPct > 100) totalPct = 100;
                $('#bar-total').css('width', totalPct + '%'); $('#total-percent').text(Math.round(totalPct) + '%');
                file.customChunkIdx++;
                if (file.customChunkIdx < totalChunks) { uploadFileChunk(file, relPath); } 
                else { finishMiniPanelItem(file.uid, false); if(file.status !== undefined) { myDropzone.emit("success", file); myDropzone.emit("complete", file); } currentFileIndex++; processNextFile(); }
            },
            error: function(jqXHR, textStatus) {
                if (textStatus === 'abort') { $(`#mp-${file.uid} .pct-text`).text('Пауза').css('color', '#f9a825'); $(`#mp-${file.uid} .speed-info`).text(''); return; }
                M.toast({html: 'Ошибка: ' + file.name, classes: 'red'}); finishMiniPanelItem(file.uid, true); currentFileIndex++; processNextFile(); 
            }
        });
    }

	function checkAllFinished() { 
        if (uploadState === 'paused' || uploadState === 'idle') return; 
        
        uploadState = 'finished'; 
        setButtonState('finished'); 
        lockInputs(true); 
        
        M.toast({html: 'Загрузка завершена!', classes: 'green'}); 
        $('#status-text').text('Готово'); 
        $('#upload-panel .panel-header span').text('Готово'); 
        
        updateDiskStats(); 
        
        // Автоматическое закрытие панели через 3 секунды (ДОБАВЛЕНО)
        setTimeout(function() {
            if (uploadState === 'finished') {
                $('#upload-panel').fadeOut(500, function() {
                    $('#upload-list').html(''); // Очищаем список после скрытия
                    $('#upload-panel .panel-header span').text('Загрузка...'); // Возвращаем заголовок
                });
                // Сбрасываем состояние интерфейса, чтобы можно было грузить снова
                stopUpload(); 
                // Но убираем тост "Загрузка остановлена", который вызывает stopUpload
                M.Toast.dismissAll(); 
            }
        }, 3000);
    }
    function finishAndReset() { modalInst.close(); performSearch(''); stopUpload(); updateDiskStats(); }
    function handleSort(col) { if (currentSort === col) currentOrder = (currentOrder === 'asc') ? 'desc' : 'asc'; else { currentSort = col; currentOrder = 'asc'; } updateSortIcons(); performSearch($('#dynamic-search').val()); }
    function updateSortIcons() { $('.sort-icon').html(''); let icon = (currentOrder === 'asc') ? 'arrow_drop_up' : 'arrow_drop_down'; $(`th[data-sort="${currentSort}"] .sort-icon`).html(icon); }
    function getActiveTabId() { let activeLink = document.querySelector('.tabs .active'); return activeLink ? activeLink.getAttribute('href') : '#tab-files'; }
    function updateButtonState() { if (uploadState !== 'idle') return; let tab = getActiveTabId(); let count = 0; let text = ""; if (tab === '#tab-files') { if(myDropzone) count = myDropzone.getAcceptedFiles().length; text = count > 0 ? `Файлов: ${count}` : ""; } else { let inp = $('#folderInput')[0]; count = inp.files ? inp.files.length : 0; text = count > 0 ? `Файлов в папке: ${count}` : ""; } $('#upload-summary-text').text(text); let btn = $('#btn-action'); if (count > 0) btn.removeClass('disabled').text('Начать загрузку').addClass('blue').removeClass('red green'); else btn.addClass('disabled').text('Начать загрузку'); }
    function lockInputs(lock) { if (lock) { $('#upload-inputs-container').addClass('inputs-blocked'); $('.tabs').addClass('inputs-blocked'); } else { $('#upload-inputs-container').removeClass('inputs-blocked'); $('.tabs').removeClass('inputs-blocked'); } }
    function setButtonState(state) { let btn = $('#btn-action'); let stopBtn = $('#btn-stop'); btn.removeClass('blue red green disabled'); if (state === 'uploading') { btn.text('Приостановить').addClass('red'); stopBtn.removeClass('hide'); } else if (state === 'paused') { btn.text('Возобновить').addClass('green'); stopBtn.removeClass('hide'); } else if (state === 'finished') { btn.text('Обновить').addClass('blue'); stopBtn.addClass('hide'); } else { stopBtn.addClass('hide'); } }
    function addMiniPanelItem(id, name) { $('#upload-panel').show(); $('.panel-body').show(); let html = `<div class="upload-item" id="mp-${id}"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;"><div class="truncate" style="font-weight:500; font-size:11px; max-width:70%">${name}</div><span class="pct-text">0%</span></div><div class="progress" style="height:4px; margin:0 0 2px 0; background:#eee"><div class="determinate" style="width: 0%"></div></div><div class="info-row"><span class="size-info">Ожидание...</span><span class="speed-info"></span></div></div>`; $('#upload-list').prepend(html); }
    function updateMiniPanelItem(id, pct, loaded, total, speed) { let el = $(`#mp-${id}`); el.find('.determinate').css('width', pct + '%'); el.find('.pct-text').text(Math.round(pct) + '%'); if(loaded !== undefined && total !== undefined) el.find('.size-info').text(`${formatFileSize(loaded)} / ${formatFileSize(total)}`); if(speed !== undefined) el.find('.speed-info').text(speed); }
    function removeMiniPanelItem(id) { $(`#mp-${id}`).remove(); }
    function finishMiniPanelItem(id, error) { let el = $(`#mp-${id}`); if(error) { el.find('.determinate').addClass('red'); el.find('.pct-text').text('Err').css('color','red !important'); } else { el.find('.determinate').addClass('green'); el.find('.pct-text').text('100%').css('color','#666 !important'); setTimeout(() => el.slideUp(()=>el.remove()), 2000); } }
    
	// Обновленная функция поиска/обновления списка
    function performSearch(q) { 
        let url = 'index.php?path='+encodeURIComponent(currentPath) + (q ? '&search='+encodeURIComponent(q) : '') + '&sort=' + currentSort + '&order=' + currentOrder + '&ajax=1'; 
        $.get(url, function(h){ 
            $('#file-list-body').html(h); 
            resetSelection(); // <--- ВАЖНО: Сбрасываем кнопки после обновления списка
        }); 
    }
	// Новая функция сброса кнопок и чекбоксов
    function resetSelection() {
        $('.file-check').prop('checked', false);
        $('.select-all-trigger').prop('checked', false);
        $('#btn-delete-multi').addClass('disabled');
        $('#btn-move-multi').addClass('disabled');
    }
    function clearSearch() { $('#dynamic-search').val(''); performSearch(''); }
    function createFolder() { let name = $('#new_folder_name').val(); if(!name) return; $.post('create_folder.php', {name: name, path: currentPath}, function(res) { if(res.success) { $('#modal-new-folder').modal('close'); performSearch($('#dynamic-search').val()); updateDiskStats(); } else { alert(res.error); } }, 'json'); }
    function deleteItem(p) { if(confirm('Удалить?')) $.post('delete.php', {paths:[p]}, function(){ performSearch($('#dynamic-search').val()); updateDiskStats(); }); }
    function viewFile(u,n,t){ let c=''; if(t=='img')c=`<img src="${u}" style="max-width:100%">`; else if(t=='other') {window.open(u);return;} else c=`<${t=='vid'?'video':'audio'} src="${u}" controls autoplay style="max-width:100%"></${t=='vid'?'video':'audio'}>`; $('#preview-content').html(c); M.Modal.getInstance($('#modal-preview')).open(); }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
</script>
</body>
</html>