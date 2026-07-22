<?php
// 云智计算 统一记忆清理 清空指定目录下的对话记忆与上传文件
set_time_limit(0);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/config.php';

// 可选访问令牌 config中clean_token非空时需携带token参数
$token = (string) ($config['security']['clean_token'] ?? '');
if ($token !== '' && !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('未授权访问');
}

$targetDir = $config['memory']['dir'];

echo "--- 开始清理记忆文件 ---\n";
echo "目标目录: {$targetDir}\n\n";

if (!is_dir($targetDir)) {
    if (mkdir($targetDir, 0755, true)) {
        echo "目录不存在，已自动创建，无需清理\n";
    } else {
        echo "错误：目录不存在且无法创建\n";
    }
    exit;
}
if (!is_writable($targetDir)) {
    exit("错误：目录不可写，请检查权限\n");
}

$deletedCount = 0;
$errorCount   = 0;

function cleanDirectory($dir, &$deletedCount, &$errorCount) {
    $items = scandir($dir);
    if ($items === false) {
        $errorCount++;
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            cleanDirectory($path, $deletedCount, $errorCount);
            @rmdir($path);
        } elseif (is_file($path)) {
            if (@unlink($path)) {
                echo "已删除: {$item}\n";
                $deletedCount++;
            } else {
                echo "错误: 无法删除 {$item}\n";
                $errorCount++;
            }
        }
    }
}

cleanDirectory($targetDir, $deletedCount, $errorCount);

echo "\n--- 清理完成 ---\n";
echo "成功删除文件总数: {$deletedCount}\n";
echo "失败次数: {$errorCount}\n";
