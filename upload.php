<?php
// 云智计算 图片上传处理 含安全校验与限流
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: {$config['security']['allowed_origin']}");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 客户端IP获取 兼容反向代理场景
function getClientIpUpload() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// 上传限流 防止恶意刷上传
function checkUploadRateLimit($config) {
    $rl = $config['security']['rate_limit'] ?? ['enabled' => false];
    if (empty($rl['enabled'])) return true;
    $dir = rtrim($rl['dir'] ?? __DIR__ . '/file/ratelimit/', '/') . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return true;
    $ip = getClientIpUpload();
    $file = $dir . 'up_' . md5($ip) . '.json';
    $now = time();
    $window = (int)($rl['window'] ?? 60);
    // 上传单独使用更严格的阈值
    $maxReqs = max(5, (int)floor(($rl['max_reqs'] ?? 30) / 6));
    $data = ['count' => 0, 'reset' => $now + $window];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['count'], $decoded['reset'])) $data = $decoded;
    }
    if ($now >= $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + $window];
    }
    $data['count']++;
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $data['count'] <= $maxReqs;
}

function uploadFail($message, $code = 400) {
    http_response_code($code);
    exit(json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadFail('只允许POST请求', 405);
}

// 限流检查
if (!checkUploadRateLimit($config)) {
    http_response_code(429);
    header('Retry-After: ' . ($config['security']['rate_limit']['window'] ?? 60));
    uploadFail('上传请求过于频繁，请稍后再试', 429);
}

// 来源校验 Origin优先 Referer兜底
$host = '';
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $host = (string) parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
} elseif (!empty($_SERVER['HTTP_REFERER'])) {
    $host = (string) parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
}
$allowedHost = (string) parse_url($config['security']['allowed_origin'], PHP_URL_HOST);
if ($host === '' || strcasecmp($host, $allowedHost) !== 0) {
    uploadFail('非法请求来源', 403);
}

if (!isset($_FILES['image'])) {
    uploadFail('没有接收到上传文件，请重新选择图片');
}
$file = $_FILES['image'];

// 将PHP上传错误码转换为友好提示
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMap = [
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器允许的大小限制，请选择更小的图片',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单允许的大小限制',
        UPLOAD_ERR_PARTIAL    => '文件上传不完整，请检查网络后重试',
        UPLOAD_ERR_NO_FILE    => '未选择文件，请重新选择图片',
        UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录异常，请稍后重试',
        UPLOAD_ERR_CANT_WRITE => '服务器写入失败，请稍后重试',
        UPLOAD_ERR_EXTENSION  => '上传被服务器扩展拦截，请稍后重试',
    ];
    uploadFail($errorMap[$file['error']] ?? '文件上传出错，请重试', 400);
}

$originalName = (string) $file['name'];
$tempPath     = (string) $file['tmp_name'];
$fileSize     = (int) $file['size'];

if ($fileSize <= 0) uploadFail('文件内容为空，请选择有效的图片');
if ($fileSize > $config['upload']['max_size']) {
    uploadFail('文件大小不能超过' . round($config['upload']['max_size'] / 1048576) . 'MB，请压缩后重试');
}

// 扩展名白名单校验
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, $config['upload']['allowed_ext'], true)) {
    uploadFail('仅支持 ' . implode('、', $config['upload']['allowed_ext']) . ' 格式的文件');
}

// 文件名长度限制 防止超长文件名引发异常
if (strlen($originalName) > 255) {
    uploadFail('文件名过长，请重命名后重试');
}

// 真实MIME类型校验 防止伪造扩展名
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = $finfo ? finfo_file($finfo, $tempPath) : '';
if ($finfo) finfo_close($finfo);
if ($realMime === '' || (strpos($realMime, 'image/') !== 0 && $realMime !== 'application/pdf')) {
    uploadFail('文件内容与格式不符，请选择有效的图片文件');
}

// 扩展名与MIME对应关系交叉校验 防止伪装
$mimeExtMap = [
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
    'png' => ['image/png'], 'gif' => ['image/gif'],
    'webp' => ['image/webp'], 'bmp' => ['image/bmp'],
    'tif' => ['image/tiff'], 'tiff' => ['image/tiff'],
    'pdf' => ['application/pdf'],
];
$expectedMimes = $mimeExtMap[$extension] ?? [];
if (!empty($expectedMimes) && !in_array($realMime, $expectedMimes, true)) {
    uploadFail('文件扩展名与真实内容不一致，请检查后重试');
}

// 图片类文件进一步校验完整性
if (strpos($realMime, 'image/') === 0 && @getimagesize($tempPath) === false) {
    uploadFail('图片文件已损坏或无法解析，请更换图片');
}

$uploadDir = $config['upload']['dir'];
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    uploadFail('上传目录创建失败，请稍后重试', 500);
}
if (!is_writable($uploadDir)) {
    uploadFail('上传目录不可写，请稍后重试', 500);
}

// 生成随机文件名 防止文件名碰撞和路径泄露
$filename   = uniqid('img_', true) . '.' . $extension;
$targetPath = $uploadDir . $filename;
if (!move_uploaded_file($tempPath, $targetPath)) {
    uploadFail('文件保存失败，请稍后重试', 500);
}
@chmod($targetPath, 0644);

// 上传日志 失败不影响主流程
@file_put_contents(
    $uploadDir . 'upload.log',
    sprintf("[%s] IP: %s | File: %s | Size: %d bytes | MIME: %s\n", date('Y-m-d H:i:s'), getClientIpUpload(), $filename, $fileSize, $realMime),
    FILE_APPEND | LOCK_EX
);

echo json_encode([
    'success'  => true,
    'message'  => '文件上传成功',
    'filename' => $filename,
    'url'      => $config['upload']['base_url'] . $filename,
], JSON_UNESCAPED_UNICODE);
