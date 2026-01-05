<?php
require_once 'config.php';
checkAuth();

if (!isset($_POST['paths']) || !is_array($_POST['paths'])) {
    exit;
}

function rrmdir($dir) { 
    if (is_dir($dir)) { 
        $objects = scandir($dir); 
        foreach ($objects as $object) { 
            if ($object != "." && $object != "..") { 
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object))
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                else
                    unlink($dir . DIRECTORY_SEPARATOR . $object); 
            } 
        }
        rmdir($dir); 
    } elseif(is_file($dir)) {
        unlink($dir);
    }
}

foreach ($_POST['paths'] as $relPath) {
    $absPath = getSafePath($relPath);
    // Дополнительная защита: не удалять корень storage
    if ($absPath && $absPath !== realpath(STORAGE_DIR)) {
        rrmdir($absPath);
    }
}
echo 'ok';
?>