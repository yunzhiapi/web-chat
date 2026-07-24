<?php
/**
 * 云智计算后台管理系统
 * 安全入口 - 所有后台请求经此路由
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';

$action = $_GET['action'] ?? 'dashboard';

// ── 路由分发 ──
switch ($action) {
    case 'login':
        handle_login();
        break;
    case 'logout':
        admin_logout();
        header('Location: ' . ADMIN_BASE . 'index.php?action=login');
        exit;
    case 'api':
        handle_api();
        break;
    default:
        require_auth();
        show_dashboard();
}

// ═══════════════════════════════════════
// 登录处理
// ═══════════════════════════════════════
function handle_login(): void {
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        if (password_verify($password, ADMIN_PASSWORD_HASH)) {
            $token = create_admin_token();
            setcookie('admin_token', $token, [
                'expires'  => time() + ADMIN_JWT_EXPIRY,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            header('Location: ' . ADMIN_BASE . 'index.php');
            exit;
        }
        $error = '密码错误，请重试';
    }

    show_login_page($error);
}

// ═══════════════════════════════════════
// CSRF 令牌 (基于 JWT，无状态)
// ═══════════════════════════════════════
function csrf_token(): string {
    $token = $_COOKIE['admin_token'] ?? '';
    if (!$token) return '';
    $payload = jwt_decode($token);
    return $payload['csrf'] ?? '';
}
function csrf_verify(): void {
    $urlToken = $_GET['_csrf'] ?? '';
    $storedToken = csrf_token();
    if (!$urlToken || !$storedToken || !hash_equals($storedToken, $urlToken)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'CSRF 验证失败，请刷新页面重试']);
        exit;
    }
}

// ═══════════════════════════════════════
// API 接口 (需认证 + CSRF)
// ═══════════════════════════════════════
function handle_api(): void {
    require_auth();
    csrf_verify();

    $cmd = $_GET['cmd'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    switch ($cmd) {
        case 'stats':
            echo json_encode(get_stats(), JSON_UNESCAPED_UNICODE);
            break;
        case 'logs':
            $type = $_GET['type'] ?? 'today';
            $file = $type === 'today' ? get_today_log_file() : (PROJECT_ROOT . '/file/log/' . basename($type));
            echo json_encode(['content' => tail_log($file)], JSON_UNESCAPED_UNICODE);
            break;
        case 'clear_ratelimit':
            clear_ratelimit();
            echo json_encode(['ok' => true, 'message' => '限流记录已清理']);
            break;
        case 'clear_logs':
            clear_logs();
            echo json_encode(['ok' => true, 'message' => '日志已清理']);
            break;
        case 'clear_memory':
            $uid = $_GET['uid'] ?? '';
            clear_memory($uid);
            echo json_encode(['ok' => true, 'message' => $uid ? "用户 $uid 记忆已清除" : '所有记忆已清除']);
            break;
        case 'log_files':
            $files = [];
            $logDir = PROJECT_ROOT . '/file/log/';
            if (is_dir($logDir)) {
                foreach (new DirectoryIterator($logDir) as $f) {
                    if ($f->isDot()) continue;
                    $files[] = ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => date('Y-m-d H:i:s', $f->getMTime())];
                }
            }
            rsort($files);
            echo json_encode($files, JSON_UNESCAPED_UNICODE);
            break;
        case 'memory_users':
            $users = [];
            $memDir = PROJECT_ROOT . '/file/';
            if (is_dir($memDir)) {
                foreach (new DirectoryIterator($memDir) as $f) {
                    if ($f->isDot() || !$f->isDir()) continue;
                    $name = $f->getFilename();
                    if (strlen($name) === 6 && ctype_digit($name)) {
                        $users[] = ['uid' => $name, 'size' => format_bytes(dir_size($f->getPathname())), 'files' => dir_file_count($f->getPathname())];
                    }
                }
            }
            echo json_encode($users, JSON_UNESCAPED_UNICODE);
            break;
        case 'save_config':
            save_config();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => '未知命令']);
    }
    exit;
}

// ═══════════════════════════════════════
// 统计信息
// ═══════════════════════════════════════
function get_stats(): array {
    $fileDir = PROJECT_ROOT . '/file/';
    return [
        'php_version'    => PHP_VERSION,
        'server_time'    => date('Y-m-d H:i:s'),
        'disk_free'      => format_bytes(disk_free_space(PROJECT_ROOT)),
        'memory_files'   => dir_file_count($fileDir),
        'memory_size'    => format_bytes(dir_size($fileDir)),
        'memory_users'   => count_memory_users(),
        'upload_count'   => dir_file_count($fileDir, 'jpg') + dir_file_count($fileDir, 'png') + dir_file_count($fileDir, 'webp') + dir_file_count($fileDir, 'gif'),
        'ratelimit_count'=> get_rate_limit_info()['count'],
        'code_files'     => is_dir($fileDir . 'code/') ? dir_file_count($fileDir . 'code/') : 0,
    ];
}

function count_memory_users(): int {
    $dir = PROJECT_ROOT . '/file/';
    if (!is_dir($dir)) return 0;
    $count = 0;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot() || !$f->isDir()) continue;
        $name = $f->getFilename();
        if (strlen($name) === 6 && ctype_digit($name)) $count++;
    }
    return $count;
}

function clear_ratelimit(): void {
    $dir = PROJECT_ROOT . '/file/ratelimit/';
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot()) continue;
        @unlink($f->getPathname());
    }
}

function clear_logs(): void {
    $dir = PROJECT_ROOT . '/file/log/';
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) {
        if ($f->isDot()) continue;
        @unlink($f->getPathname());
    }
}

function clear_memory(string $uid = ''): void {
    $dir = PROJECT_ROOT . '/file/';
    if ($uid && strlen($uid) === 6 && ctype_digit($uid)) {
        $target = $dir . $uid;
        if (is_dir($target)) array_map('unlink', glob("$target/*"));
    } else {
        // 只清理 6 位数字的用户目录
        foreach (new DirectoryIterator($dir) as $f) {
            if ($f->isDot() || !$f->isDir()) continue;
            $name = $f->getFilename();
            if (strlen($name) === 6 && ctype_digit($name)) {
                array_map('unlink', glob($f->getPathname() . '/*'));
            }
        }
    }
}

function save_config(): void {
    $configFile = PROJECT_ROOT . '/config.php';
    if (!is_writable($configFile)) {
        echo json_encode(['error' => '配置文件不可写，请检查权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['error' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 备份原配置
    copy($configFile, $configFile . '.bak.' . date('YmdHis'));

    // 读取当前配置并在内存中修改
    $config = include $configFile;
    if (!is_array($config)) {
        echo json_encode(['error' => '配置文件解析失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($input['api_url']) && filter_var($input['api_url'], FILTER_VALIDATE_URL)) {
        $config['api']['url'] = $input['api_url'];
    }
    if (!empty($input['api_key'])) {
        $config['api']['key'] = trim($input['api_key']);
    }
    if (isset($input['api_timeout']) && (int)$input['api_timeout'] > 0) {
        $config['api']['timeout'] = (int)$input['api_timeout'];
    }
    if (isset($input['rate_window']) && (int)$input['rate_window'] > 0) {
        $config['security']['rate_limit']['window'] = (int)$input['rate_window'];
    }
    if (isset($input['rate_max']) && (int)$input['rate_max'] > 0) {
        $config['security']['rate_limit']['max_reqs'] = (int)$input['rate_max'];
    }
    if (isset($input['max_question']) && (int)$input['max_question'] > 0) {
        $config['security']['max_question_length'] = (int)$input['max_question'];
    }
    if (isset($input['max_rounds']) && (int)$input['max_rounds'] > 0) {
        $config['memory']['max_rounds'] = (int)$input['max_rounds'];
    }
    if (isset($input['max_upload']) && (int)$input['max_upload'] > 0) {
        $config['upload']['max_size'] = (int)$input['max_upload'] * 1048576;
    }
    if (isset($input['log_days']) && (int)$input['log_days'] > 0) {
        $config['security']['log']['retention_days'] = (int)$input['log_days'];
    }
    if (!empty($input['allowed_origin'])) {
        $config['security']['allowed_origin'] = trim($input['allowed_origin']);
    }

    // 安全写出 PHP 数组
    $export = "<?php\n// 云智计算 全局配置文件\nreturn " . var_export($config, true) . ";\n";
    $export = preg_replace('/=> \n\s+array \(/', '=> array (', $export);

    if (file_put_contents($configFile, $export, LOCK_EX) === false) {
        echo json_encode(['error' => '写入配置文件失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($configFile, true);
    }

    // ── 模块配置保存 ──
    if (($input['_section'] ?? '') === 'modules') {
        foreach ($input as $key => $value) {
            if (preg_match('/^mod_(.+)_model$/', $key, $m) && isset($config['modules'][$m[1]])) {
                $config['modules'][$m[1]]['model'] = trim($value);
            }
            if (preg_match('/^mod_(.+)_tokens$/', $key, $m) && isset($config['modules'][$m[1]])) {
                $config['modules'][$m[1]]['max_tokens'] = max(1, (int)$value);
            }
            if (preg_match('/^mod_(.+)_system$/', $key, $m) && isset($config['modules'][$m[1]])) {
                $config['modules'][$m[1]]['system'] = trim($value);
            }
        }
        $export = "<?php\n// 云智计算 全局配置文件\nreturn " . var_export($config, true) . ";\n";
        $export = preg_replace('/=> \n\s+array \(/', '=> array (', $export);
        file_put_contents($configFile, $export, LOCK_EX);
        echo json_encode(['ok' => true, 'message' => '模块配置已保存并生效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => '配置已保存并生效'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════
// 登录页面
// ═══════════════════════════════════════
function show_login_page(string $error = ''): void {
    $errorHtml = $error ? '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation mr-2"></i>' . htmlspecialchars($error) . '</div>' : '';
    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>云智计算 - 后台管理登录</title>
<link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #f0f4f8;
    --card-bg: #ffffff;
    --text: #1a202c;
    --muted: #718096;
    --primary: #0f8f8c;
    --primary-dark: #0a6f73;
    --error: #e53e3e;
    --line: rgba(0,0,0,0.08);
    --shadow: 0 20px 60px rgba(0,0,0,0.12);
}
[data-theme="dark"] { --bg: #0f172a; --card-bg: #1e293b; --text: #e2e8f0; --muted: #94a3b8; --line: rgba(255,255,255,0.08); --shadow: 0 20px 60px rgba(0,0,0,0.5); }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: var(--bg);
    color: var(--text);
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}
.login-card {
    background: var(--card-bg);
    border-radius: 18px;
    box-shadow: var(--shadow);
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 400px;
    border: 1px solid var(--line);
}
.login-header { text-align: center; margin-bottom: 2rem; }
.login-header .icon { font-size: 2.8rem; color: var(--primary); margin-bottom: 0.75rem; }
.login-header h1 { font-size: 1.35rem; font-weight: 700; color: var(--text); }
.login-header p { color: var(--muted); font-size: 0.85rem; margin-top: 0.4rem; }
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; margin-bottom: 0.4rem; font-size: 0.85rem; font-weight: 600; color: var(--text); }
.input-field {
    width: 100%; padding: 0.8rem 1rem;
    border: 1px solid var(--line);
    border-radius: 12px; font-size: 1rem;
    background: var(--card-bg); color: var(--text);
    transition: all 0.2s;
}
.input-field:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(15,143,140,0.15); }
.btn-submit {
    width: 100%; padding: 0.85rem;
    background: linear-gradient(135deg, var(--primary), #12a89f);
    color: #fff; border: none; border-radius: 12px;
    font-size: 1rem; font-weight: 600; cursor: pointer;
    transition: all 0.2s;
}
.btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(15,143,140,0.3); }
.alert { padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem; }
.alert-error { background: #fff5f5; color: var(--error); border: 1px solid #fed7d7; }
.back-link { display: block; text-align: center; margin-top: 1.5rem; color: var(--muted); font-size: 0.8rem; text-decoration: none; }
.back-link:hover { color: var(--primary); }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
        <h1>云智计算 后台管理</h1>
        <p>请输入管理员密码登录</p>
    </div>
    ' . $errorHtml . '
    <form method="post">
        <div class="form-group">
            <label for="password">管理员密码</label>
            <input type="password" id="password" name="password" class="input-field" placeholder="请输入密码" autofocus required>
        </div>
        <button type="submit" class="btn-submit">
            <i class="fa-solid fa-right-to-bracket mr-2"></i>登录后台
        </button>
    </form>
    <a href="../" class="back-link"><i class="fa-solid fa-arrow-left mr-1"></i>返回对话平台</a>
</div>
</body>
</html>';
    exit;
}

// ═══════════════════════════════════════
// 仪表盘页面
// ═══════════════════════════════════════
function show_dashboard(): void {
    $config = include PROJECT_ROOT . '/config.php';
    $stats = get_stats();
    $rateInfo = get_rate_limit_info();
    $logContent = tail_log(get_today_log_file(), 80);
    $tokenStats = get_token_stats();

    // API Key 脱敏
    $apiKey = $config['api']['key'] ?? '';
    $maskedKey = substr($apiKey, 0, 8) . '****' . substr($apiKey, -4);

    $modules = $config['modules'] ?? [];
    $moduleCount = count($modules);
    $csrf = csrf_token();

    echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>云智计算 - 后台管理面板</title>
<link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #f0f4f8;
    --card-bg: #ffffff;
    --text: #1a202c;
    --muted: #718096;
    --primary: #0f8f8c;
    --primary-dark: #0a6f73;
    --accent: #e45f3d;
    --success: #10b981;
    --warning: #f59e0b;
    --error: #ef4444;
    --line: rgba(0,0,0,0.07);
    --shadow: 0 2px 12px rgba(0,0,0,0.06);
}
[data-theme="dark"] {
    --bg: #0b1120;
    --card-bg: #1a2332;
    --text: #e2e8f0;
    --muted: #94a3b8;
    --line: rgba(255,255,255,0.06);
    --shadow: 0 2px 12px rgba(0,0,0,0.3);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
    background: var(--bg); color: var(--text);
    min-height: 100vh; -webkit-font-smoothing: antialiased;
}
/* ── 顶栏 ── */
.topbar {
    background: var(--card-bg); border-bottom: 1px solid var(--line);
    padding: 0 1.5rem; height: 56px; display: flex; align-items: center;
    justify-content: space-between; position: sticky; top: 0; z-index: 100;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.topbar h1 { font-size: 1.1rem; font-weight: 700; }
.topbar h1 i { color: var(--primary); margin-right: 0.4rem; }
.topbar .actions { display: flex; gap: 0.75rem; align-items: center; }
.btn { padding: 0.45rem 0.9rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; }
.btn-ghost { background: none; color: var(--muted); border: 1px solid var(--line); }
.btn-ghost:hover { background: rgba(0,0,0,0.04); color: var(--text); }
.btn-danger { background: var(--error); color: #fff; }
.btn-danger:hover { background: #dc2626; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-sm { padding: 0.25rem 0.6rem; font-size: 0.7rem; border-radius: 6px; }
/* ── 暗色覆盖 ── */
[data-theme="dark"] .stat-card { background: var(--card-bg); border-color: var(--line); }
[data-theme="dark"] table tr:hover { background: rgba(255,255,255,0.03); }
[data-theme="dark"] .btn-ghost:hover { background: rgba(255,255,255,0.06); color: #e2e8f0; }
[data-theme="dark"] .log-viewer { background: #0d1520; }
[data-theme="dark"] .topbar { border-color: var(--line); }
[data-theme="dark"] .badge-success { background: #064e3b; color: #6ee7b7; }
[data-theme="dark"] .badge-warning { background: #78350f; color: #fcd34d; }
[data-theme="dark"] .badge-error { background: #7f1d1d; color: #fca5a5; }
[data-theme="dark"] .stat-icon.blue { background: #1e3a5f; color: #60a5fa; }
[data-theme="dark"] .stat-icon.green { background: #064e3b; color: #34d399; }
[data-theme="dark"] .stat-icon.amber { background: #78350f; color: #fbbf24; }
[data-theme="dark"] .stat-icon.red { background: #7f1d1d; color: #f87171; }
[data-theme="dark"] .stat-icon.purple { background: #4c1d95; color: #a78bfa; }
/* ── 主布局 ── */
.layout { max-width: 1280px; margin: 0 auto; padding: 1.5rem; display: grid; gap: 1.25rem; }
/* ── 统计卡片 ── */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.stat-card {
    background: var(--card-bg); border-radius: 14px; padding: 1.2rem 1.25rem;
    border: 1px solid var(--line); box-shadow: var(--shadow);
    display: flex; align-items: flex-start; gap: 1rem;
}
.stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.stat-icon.blue { background: #dbeafe; color: #2563eb; }
.stat-icon.green { background: #d1fae5; color: #059669; }
.stat-icon.amber { background: #fef3c7; color: #d97706; }
.stat-icon.red { background: #fee2e2; color: #dc2626; }
.stat-icon.purple { background: #ede9fe; color: #7c3aed; }
.stat-value { font-size: 1.6rem; font-weight: 800; line-height: 1.2; }
.stat-label { font-size: 0.78rem; color: var(--muted); margin-top: 0.15rem; }
/* ── 面板卡片 ── */
.panel {
    background: var(--card-bg); border-radius: 14px; border: 1px solid var(--line);
    box-shadow: var(--shadow); overflow: hidden;
}
.panel-header {
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--line);
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 700; font-size: 0.95rem;
}
.panel-body { padding: 1rem 1.25rem; }
.panel-body.scroll { max-height: 360px; overflow-y: auto; }
/* ── 日志 ── */
.log-viewer {
    background: #1e293b; color: #e2e8f0; border-radius: 10px;
    padding: 1rem; font-family: "Fira Code", "Consolas", monospace;
    font-size: 0.75rem; line-height: 1.6; white-space: pre-wrap;
    overflow-x: auto; max-height: 280px; overflow-y: auto;
}
/* ── 表格 ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
th, td { padding: 0.6rem 0.8rem; text-align: left; border-bottom: 1px solid var(--line); }
th { font-weight: 700; color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }
tr:hover { background: rgba(0,0,0,0.02); }
.badge {
    display: inline-block; padding: 0.15rem 0.55rem; border-radius: 6px;
    font-size: 0.7rem; font-weight: 700;
}
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-error { background: #fee2e2; color: #991b1b; }
/* ── 网格 ── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
@media (max-width: 768px) {
    .grid-2 { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .layout { padding: 0.75rem; gap: 0.75rem; }
    .topbar { padding: 0 0.75rem; height: 48px; }
    .topbar h1 { font-size: 0.9rem; }
    .btn { font-size: 0.72rem; padding: 0.35rem 0.65rem; }
    .stat-card { padding: 0.8rem 0.9rem; }
    .stat-value { font-size: 1.3rem; }
    .stat-icon { width: 34px; height: 34px; font-size: 0.9rem; }
    .panel-header { padding: 0.75rem 0.9rem; font-size: 0.85rem; }
    .panel-body { padding: 0.75rem 0.9rem; }
    .log-viewer { font-size: 0.65rem; max-height: 200px; padding: 0.75rem; }
    table { font-size: 0.72rem; }
    th, td { padding: 0.45rem 0.5rem; }
    .form-row { flex-direction: column; align-items: stretch; gap: 0.3rem; }
    .form-row label { width: auto; }
    .input-sm { width: 100% !important; max-width: none; }
    .w-20, .w-24 { max-width: none !important; width: 100% !important; }
    .topbar .actions { gap: 0.3rem; }
    .topbar .actions .btn span { display: none; }
    .modal-content { max-width: 95%; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
    .topbar { height: 42px; padding: 0 0.5rem; }
    .topbar h1 { font-size: 0.8rem; }
    .topbar h1 i { margin-right: 0.2rem; }
    .btn { font-size: 0.68rem; padding: 0.3rem 0.5rem; gap: 0.2rem; }
    .layout { padding: 0.5rem; gap: 0.5rem; }
    .stat-card { padding: 0.65rem 0.75rem; border-radius: 10px; }
    .stat-value { font-size: 1.15rem; }
    .stat-label { font-size: 0.68rem; }
    .panel { border-radius: 10px; }
    .log-viewer { max-height: 160px; font-size: 0.6rem; }
    .toast { font-size: 0.75rem; padding: 0.5rem 0.9rem; left: 0.5rem; right: 0.5rem; transform: none; border-radius: 12px; text-align: center; }
}
/* ── toast ── */
.toast {
    position: fixed; top: 1rem; left: 50%; transform: translateX(-50%);
    padding: 0.6rem 1.25rem; border-radius: 20px; font-size: 0.85rem;
    font-weight: 600; z-index: 999; box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    animation: slideDown 0.3s ease;
}
.toast-success { background: var(--success); color: #fff; }
.toast-error { background: var(--error); color: #fff; }
@keyframes slideDown { from { opacity: 0; transform: translateX(-50%) translateY(-20px); } }
/* ── 配置编辑器 ── */
.hidden { display: none !important; }
.form-row { display: flex; align-items: center; gap: 0.75rem; }
.form-row label { width: 120px; font-size: 0.8rem; font-weight: 600; color: var(--muted); flex-shrink: 0; }
.input-sm { padding: 0.45rem 0.65rem; border: 1px solid var(--line); border-radius: 8px; font-size: 0.8rem; background: var(--card-bg); color: var(--text); font-family: monospace; flex: 1; }
.input-sm:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(15,143,140,0.12); }
.w-20 { max-width: 80px; flex: none; }
.w-24 { max-width: 100px; flex: none; }
</style>
</head>
<body>
<div class="topbar">
    <h1><i class="fa-solid fa-gauge-high"></i>云智计算 后台管理</h1>
    <div class="actions">
        <a href="../" class="btn btn-ghost"><i class="fa-solid fa-comments"></i>对话平台</a>
        <a href="?action=logout" class="btn btn-ghost"><i class="fa-solid fa-right-from-bracket"></i>退出</a>
    </div>
</div>

<div class="layout">
    <!-- ── Token 用量统计 ── -->
    <div class="stats-grid" style="margin-bottom:0.25rem">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-coins"></i></div>
            <div><div class="stat-value">' . number_format($tokenStats['todayStats']['total']) . '</div><div class="stat-label">今日 Token</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-chart-bar"></i></div>
            <div><div class="stat-value">' . ($tokenStats['todayStats']['calls'] > 0 ? number_format($tokenStats['todayStats']['total'] / $tokenStats['todayStats']['calls']) : 0) . '</div><div class="stat-label">今日平均/次</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fa-solid fa-phone"></i></div>
            <div><div class="stat-value">' . $tokenStats['todayStats']['calls'] . '</div><div class="stat-label">今日调用次数</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fa-solid fa-database"></i></div>
            <div><div class="stat-value">' . number_format($tokenStats['total']['total']) . '</div><div class="stat-label">累计 Token</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-calculator"></i></div>
            <div><div class="stat-value">' . ($tokenStats['total']['calls'] > 0 ? number_format($tokenStats['total']['total'] / $tokenStats['total']['calls']) : 0) . '</div><div class="stat-label">累计平均/次</div></div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
            <div><div class="stat-value">' . $stats['memory_users'] . '</div><div class="stat-label">活跃用户记忆</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-file-lines"></i></div>
            <div><div class="stat-value">' . $stats['memory_files'] . '</div><div class="stat-label">记忆文件总数</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fa-solid fa-gauge"></i></div>
            <div><div class="stat-value">' . $stats['ratelimit_count'] . '</div><div class="stat-label">限流记录</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fa-solid fa-database"></i></div>
            <div><div class="stat-value">' . $stats['memory_size'] . '</div><div class="stat-label">记忆数据总量</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-code"></i></div>
            <div><div class="stat-value">' . $stats['code_files'] . '</div><div class="stat-label">代码文件</div></div>
        </div>
    </div>

    <!-- 系统信息 + 配置 -->
    <div class="grid-2">
        <div class="panel">
            <div class="panel-header"><i class="fa-solid fa-server mr-2"></i>系统信息</div>
            <div class="panel-body">
                <table>
                    <tr><td style="color:var(--muted)">PHP 版本</td><td><strong>' . $stats['php_version'] . '</strong></td></tr>
                    <tr><td style="color:var(--muted)">服务器时间</td><td>' . $stats['server_time'] . '</td></tr>
                    <tr><td style="color:var(--muted)">磁盘可用</td><td>' . $stats['disk_free'] . '</td></tr>
                    <tr><td style="color:var(--muted)">模块数量</td><td>' . $moduleCount . ' 个</td></tr>
                    <tr><td style="color:var(--muted)">上传文件</td><td>' . $stats['upload_count'] . ' 个</td></tr>
                </table>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header"><i class="fa-solid fa-key mr-2"></i>API 配置</div>
            <div class="panel-body">
                <form onsubmit="saveConfigSection(event, \'api\')" style="display:grid;gap:0.65rem;">
                    <div class="form-row"><label>API 端点</label><input name="api_url" value="' . htmlspecialchars($config['api']['url']) . '" class="input-sm"></div>
                    <div class="form-row"><label>API Key</label><input name="api_key" value="' . htmlspecialchars($config['api']['key']) . '" class="input-sm"></div>
                    <div class="form-row"><label>超时(秒)</label><input name="api_timeout" type="number" value="' . (int)($config['api']['timeout'] ?? 120) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>限流窗口(秒)</label><input name="rate_window" type="number" value="' . (int)($config['security']['rate_limit']['window'] ?? 60) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>限流最大次数</label><input name="rate_max" type="number" value="' . (int)($config['security']['rate_limit']['max_reqs'] ?? 30) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>最大问题长度</label><input name="max_question" type="number" value="' . (int)($config['security']['max_question_length'] ?? 30000) . '" class="input-sm w-24"></div>
                    <div class="form-row"><label>记忆最大轮数</label><input name="max_rounds" type="number" value="' . (int)($config['memory']['max_rounds'] ?? 30) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>上传最大(MB)</label><input name="max_upload" type="number" value="' . round(($config['upload']['max_size'] ?? 10485760) / 1048576) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>日志保留(天)</label><input name="log_days" type="number" value="' . (int)($config['security']['log']['retention_days'] ?? 7) . '" class="input-sm w-20"></div>
                    <div class="form-row"><label>允许来源</label><input name="allowed_origin" value="' . htmlspecialchars($config['security']['allowed_origin']) . '" class="input-sm"></div>
                    <button type="submit" class="btn btn-primary btn-sm" style="justify-self:start"><i class="fa-solid fa-save"></i> 保存 API 配置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 模块配置 -->
    <div class="panel">
        <div class="panel-header"><i class="fa-solid fa-cubes mr-2"></i>AI 模块配置 (' . $moduleCount . ' 个)</div>
        <div class="panel-body scroll">
            <form onsubmit="saveConfigSection(event, \'modules\')">
            <div class="table-wrap">
            <table>
                <thead><tr><th>模块</th><th>模型</th><th>Tokens</th><th>系统提示词</th></tr></thead>
                <tbody>';
    foreach ($modules as $name => $mod) {
        $sysPrompt = $mod['system'] ?? '';
        echo '<tr>
            <td><span class="badge badge-success">' . htmlspecialchars($name) . '</span></td>
            <td><input name="mod_' . htmlspecialchars($name) . '_model" value="' . htmlspecialchars($mod['model'] ?? '') . '" class="input-sm" style="min-width:130px"></td>
            <td><input name="mod_' . htmlspecialchars($name) . '_tokens" type="number" value="' . (int)($mod['max_tokens'] ?? 0) . '" class="input-sm w-24"></td>
            <td><input name="mod_' . htmlspecialchars($name) . '_system" value="' . htmlspecialchars($sysPrompt) . '" class="input-sm" style="min-width:250px"></td>
        </tr>';
    }
    echo '</tbody></table></div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.75rem"><i class="fa-solid fa-save"></i> 保存模块配置</button>
            </form>
        </div>
    </div>';

    // ── 分模型 Token 统计 ──
    if (count($tokenStats['byModel']) > 0) {
        echo '<div class="panel" style="margin-bottom:1.25rem">
            <div class="panel-header"><i class="fa-solid fa-microchip mr-2"></i>分模型 Token 用量</div>
            <div class="panel-body scroll" style="max-height:260px">
                <div class="table-wrap"><table>
                <thead><tr><th>模型</th><th>调用次数</th><th>Prompt</th><th>Completion</th><th>合计</th><th>平均/次</th></tr></thead><tbody>';
        arsort($tokenStats['byModel']);
        foreach ($tokenStats['byModel'] as $model => $m) {
            $avg = $m['calls'] > 0 ? number_format($m['total'] / $m['calls']) : 0;
            echo '<tr>
                <td><code style="font-size:0.78rem">' . htmlspecialchars($model) . '</code></td>
                <td>' . $m['calls'] . '</td>
                <td>' . number_format($m['prompt']) . '</td>
                <td>' . number_format($m['completion']) . '</td>
                <td><strong>' . number_format($m['total']) . '</strong></td>
                <td>' . $avg . '</td>
            </tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    // 操作区
    echo '
    <div class="grid-2">
        <div class="panel">
            <div class="panel-header">
                <span><i class="fa-solid fa-broom mr-2"></i>快速操作</span>
            </div>
            <div class="panel-body" style="display:flex;flex-wrap:wrap;gap:0.6rem;">
                <button class="btn btn-sm btn-ghost" onclick="apiAction(\'clear_ratelimit\')"><i class="fa-solid fa-gauge"></i> 清理限流记录</button>
                <button class="btn btn-sm btn-ghost" onclick="apiAction(\'clear_logs\')"><i class="fa-solid fa-eraser"></i> 清理日志文件</button>
                <button class="btn btn-sm btn-ghost" onclick="apiAction(\'clear_memory\')"><i class="fa-solid fa-trash-can"></i> 清理所有记忆</button>
                <button class="btn btn-sm btn-ghost" onclick="location.reload()"><i class="fa-solid fa-rotate"></i> 刷新面板</button>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header">
                <span><i class="fa-solid fa-triangle-exclamation mr-2"></i>限流状态</span>
                <span class="badge ' . ($rateInfo['count'] > 10 ? 'badge-error' : 'badge-success') . '">' . $rateInfo['count'] . ' 条</span>
            </div>
            <div class="panel-body scroll" style="max-height:180px;">';
    if ($rateInfo['count'] === 0) {
        echo '<p style="color:var(--muted);text-align:center;padding:2rem 0">暂无活跃限流记录</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>IP / 标识</th><th>大小</th><th>更新时间</th></tr></thead><tbody>';
        foreach (array_slice($rateInfo['files'], 0, 50) as $f) {
            echo '<tr><td><code>' . htmlspecialchars($f['name']) . '</code></td><td>' . $f['size'] . ' B</td><td>' . $f['time'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></div></div>';

    // ── 日志查看器 ──
    echo '
    <div class="panel">
        <div class="panel-header">
            <span><i class="fa-solid fa-scroll mr-2"></i>系统日志 (' . date('Y-m-d') . ')</span>
            <button class="btn btn-sm btn-ghost" onclick="refreshLogs()"><i class="fa-solid fa-rotate"></i> 刷新</button>
        </div>
        <div class="panel-body">
            <div class="log-viewer" id="log-viewer">' . htmlspecialchars($logContent ?: '暂无日志') . '</div>
        </div>
    </div>';
    ?>
</div>

<div id="toast-container"></div>

<script>
const CSRF_TOKEN = '<?php echo $csrf; ?>';
const ADMIN_BASE_URL = '<?php echo ADMIN_BASE; ?>';

// ── 暗色模式自动检测 ──
(function() {
    const stored = localStorage.getItem('yunzhi_admin_theme');
    const theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
})();

async function apiAction(cmd, extra = '') {
    try {
        const resp = await fetch('?action=api&cmd=' + cmd + '&_csrf=' + encodeURIComponent(CSRF_TOKEN) + extra, { credentials: 'same-origin' });
        const data = await resp.json();
        showToast(data.message || data.error || '完成', data.ok !== false && !data.error);
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        showToast('请求失败: ' + e.message, false);
    }
}
async function refreshLogs() {
    try {
        const resp = await fetch('?action=api&cmd=logs&type=today&_csrf=' + encodeURIComponent(CSRF_TOKEN), { credentials: 'same-origin' });
        const data = await resp.json();
        document.getElementById('log-viewer').textContent = data.content || '暂无日志';
        showToast('日志已刷新', true);
    } catch (e) {
        showToast('刷新失败', false);
    }
}
function showToast(msg, ok = true) {
    const el = document.createElement('div');
    el.className = 'toast ' + (ok ? 'toast-success' : 'toast-error');
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.remove(), 2500);
}
async function saveConfigSection(e, section) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form));
    data._section = section;
    try {
        const resp = await fetch('?action=api&cmd=save_config&_csrf=' + encodeURIComponent(CSRF_TOKEN), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        showToast(result.message || result.error || '完成', result.ok !== false && !result.error);
        if (result.ok) setTimeout(() => location.reload(), 1000);
    } catch (e) {
        showToast('保存失败: ' + e.message, false);
    }
}
</script>
</body>
</html>
<?php
    exit;
}
