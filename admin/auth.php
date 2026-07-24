<?php
/**
 * JWT 认证模块 (纯 PHP 实现，零外部依赖)
 */

require_once __DIR__ . '/config.php';

// ── Base64URL 编解码 ──
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// ── JWT 编码 ──
function jwt_encode(array $payload, string $secret = ADMIN_JWT_SECRET): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [];
    $segments[] = base64url_encode(json_encode($header));
    $segments[] = base64url_encode(json_encode($payload));
    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, $secret, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

// ── JWT 解码 ──
function jwt_decode(string $token, string $secret = ADMIN_JWT_SECRET): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    // 验证签名
    $signingInput = "$headerB64.$payloadB64";
    $expectedSig = base64url_encode(hash_hmac('sha256', $signingInput, $secret, true));
    if (!hash_equals($expectedSig, $signatureB64)) return null;

    $payload = json_decode(base64url_decode($payloadB64), true);
    if (!$payload) return null;

    // 检查过期
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}

// ── 创建登录 Token ──
function create_admin_token(): string {
    $csrf = bin2hex(random_bytes(32));
    return jwt_encode([
        'sub'  => 'admin',
        'iat'  => time(),
        'exp'  => time() + ADMIN_JWT_EXPIRY,
        'jti'  => bin2hex(random_bytes(8)),
        'csrf' => $csrf,
    ]);
}

// ── 验证当前请求是否已认证 ──
function is_authenticated(): bool {
    $token = $_COOKIE['admin_token'] ?? '';
    if (!$token) return false;
    $payload = jwt_decode($token);
    return $payload !== null;
}

// ── 强制认证（未认证则跳转登录） ──
function require_auth(): void {
    if (!is_authenticated()) {
        $loginUrl = ADMIN_BASE . 'index.php?action=login';
        header('Location: ' . $loginUrl);
        exit;
    }
}

// ── 安全退出 ──
function admin_logout(): void {
    setcookie('admin_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── 目录大小计算 ──
function dir_size(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->getSize();
    }
    return $size;
}

// ── 文件数量统计 ──
function dir_file_count(string $dir, string $ext = ''): int {
    if (!is_dir($dir)) return 0;
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$ext || $f->getExtension() === $ext) $count++;
    }
    return $count;
}

// ── 格式化字节 ──
function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// ── 读取日志最后 N 行 ──
function tail_log(string $file, int $lines = 100): string {
    if (!file_exists($file)) return '暂无日志';
    $content = file_get_contents($file);
    if ($content === false) return '读取失败';
    $allLines = explode("\n", $content);
    $allLines = array_filter($allLines, fn($l) => trim($l) !== '');
    $last = array_slice($allLines, -$lines);
    return implode("\n", $last);
}

// ── 获取当天日志文件 ──
function get_today_log_file(): string {
    $logDir = PROJECT_ROOT . '/file/log/';
    $today = date('Y-m-d');
    return $logDir . 'error-' . $today . '.log';
}

// ── 获取限流状态 ──
function get_rate_limit_info(): array {
    $dir = PROJECT_ROOT . '/file/ratelimit/';
    if (!is_dir($dir)) return ['count' => 0, 'files' => []];
    $files = [];
    $totalCount = 0;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot()) continue;
        $files[] = [
            'name' => $f->getFilename(),
            'size' => $f->getSize(),
            'time' => date('Y-m-d H:i:s', $f->getMTime()),
        ];
        $totalCount++;
    }
    return ['count' => $totalCount, 'files' => $files];
}
