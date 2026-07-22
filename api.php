<?php
// 云智计算 统一API端点 负责所有功能模块的请求转发与结果输出
$config = require __DIR__ . '/config.php';

// 客户端IP获取 兼容反向代理场景
function getClientIp() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// 错误日志记录 失败不影响主流程
function writeLog($message, $type = 'error') {
    global $config;
    $logConfig = $config['security']['log'] ?? ['enabled' => false];
    if (empty($logConfig['enabled'])) return;
    $logDir = rtrim($logConfig['dir'] ?? __DIR__ . '/file/log/', '/') . '/';
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) return;
    $file = $logDir . date('Y-m-d') . '.log';
    $line = sprintf("[%s] [%s] [IP:%s] [UA:%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($type),
        getClientIp(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 200),
        $message
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // 自动清理过期日志
    $retention = $logConfig['retention_days'] ?? 7;
    if (mt_rand(1, 100) === 1) {
        $cutoff = time() - $retention * 86400;
        foreach (glob($logDir . '*.log') ?: [] as $old) {
            if (filemtime($old) < $cutoff) @unlink($old);
        }
    }
}

// 基于IP的简单限流 防止恶意刷接口
function checkRateLimit($config) {
    $rl = $config['security']['rate_limit'] ?? ['enabled' => false];
    if (empty($rl['enabled'])) return true;
    $dir = rtrim($rl['dir'] ?? __DIR__ . '/file/ratelimit/', '/') . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return true;
    $ip = getClientIp();
    $file = $dir . md5($ip) . '.json';
    $now = time();
    $window = (int)($rl['window'] ?? 60);
    $maxReqs = (int)($rl['max_reqs'] ?? 30);
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

// 来源校验与CORS
$allowedOrigin = $config['security']['allowed_origin'];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = parse_url($_SERVER['HTTP_REFERER']);
    if (isset($ref['scheme'], $ref['host'])) {
        $requestOrigin = $ref['scheme'] . '://' . $ref['host'];
    }
}
if ($requestOrigin !== $allowedOrigin) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    writeLog('非法来源访问 被拦截 Origin=' . $requestOrigin, 'security');
    exit('【违规拦截】您正在请求云智API内部智能助手服务，该接口并未在源码以外进行任何公示，本站已记录您的IP和浏览器标识，请勿多次违规访问否则直接拉黑。');
}
header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('仅支持POST或GET请求');
}

// 限流检查
if (!checkRateLimit($config)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: ' . ($config['security']['rate_limit']['window'] ?? 60));
    writeLog('触发限流 拒绝服务', 'security');
    exit('请求过于频繁，请稍后再试');
}

// 统一响应函数
function respond($content, $type, array $extra = []) {
    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => true, 'content' => $content], $extra), JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    }
    exit;
}
function respondError($message, $code, $type) {
    http_response_code($code);
    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    writeLog('响应错误 ' . $code . ' ' . $message, 'error');
    exit;
}

// 记忆读写 使用紧凑JSON加gzip压缩存储
function loadHistory($file) {
    $history = [];
    if (!is_file($file)) return $history;
    $json = @gzdecode((string) @file_get_contents($file));
    if ($json === false) return $history;
    $compact = json_decode($json, true);
    if (!is_array($compact)) return $history;
    foreach ($compact as $item) {
        if (!isset($item['r'], $item['c'])) continue;
        $role = $item['r'] === 'a' ? 'assistant' : ($item['r'] === 's' ? 'system' : 'user');
        $history[] = ['role' => $role, 'content' => $item['c']];
    }
    return $history;
}
function saveHistory($file, $history, $maxRounds) {
    $maxItems = $maxRounds * 2;
    if (count($history) > $maxItems) $history = array_slice($history, -$maxItems);
    $compact = [];
    foreach ($history as $msg) {
        $compact[] = [
            'r' => $msg['role'] === 'assistant' ? 'a' : ($msg['role'] === 'system' ? 's' : 'u'),
            'c' => $msg['content'],
        ];
    }
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($file, gzencode(json_encode($compact, JSON_UNESCAPED_UNICODE), 6), LOCK_EX);
}
// 共享记忆模块 默认对话 深度思考 联网搜索 搜题解答 帮我写作 图片理解共用一份记忆 代码编程独立记忆
function memoryFileFor($action, $uid, $config) {
    if (in_array($action, $config['memory']['shared_modules'], true)) {
        return $config['memory']['dir'] . $uid . '.json.gz';
    }
    if ($action === 'code') {
        return $config['memory']['code_dir'] . $uid . '.json.gz';
    }
    return null;
}

// 调用上游对话接口 返回内容 错误信息 状态码 原始消息体
function callChatApi($config, array $payload, $timeout = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['api']['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => $timeout ?? $config['api']['timeout'],
        CURLOPT_CONNECTTIMEOUT => $config['api']['connect_timeout'],
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $config['api']['key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        writeLog('上游连接失败 ' . $curlErr, 'error');
        return [null, 'AI服务连接失败，请稍后重试', 502];
    }
    if ($httpCode === 401 || $httpCode === 403 || $httpCode === 429) {
        return [null, '模型服务暂时不可用或请求过于频繁，请稍后重试', 429];
    }
    if ($httpCode >= 400) {
        writeLog('上游HTTP错误 ' . $httpCode, 'error');
        return [null, "AI服务返回错误（HTTP {$httpCode}），请稍后重试", 502];
    }
    $data = json_decode((string) $response, true);
    if (!is_array($data)) return [null, 'AI服务响应解析失败，请稍后重试', 502];
    if (isset($data['error'])) {
        return [null, 'AI服务错误：' . ($data['error']['message'] ?? '未知错误'), 502];
    }
    $content = $data['choices'][0]['message']['content']
        ?? ($data['content'][0]['text'] ?? null);
    if (!is_string($content) || trim($content) === '') {
        return [null, '模型未返回有效内容，请换个问法重试', 502];
    }
    $content = preg_replace('/\n{3,}/', "\n\n", trim($content));
    return [$content, null, 200, $data['choices'][0]['message'] ?? []];
}

// 从模型响应中提取图片 支持Base64或直链
function extractImageFromMessage($content, $message) {
    $candidates = [];
    $imgUrl = $message['images'][0]['image_url']['url'] ?? '';
    if (is_string($imgUrl) && $imgUrl !== '') $candidates[] = $imgUrl;
    if (is_string($content) && $content !== '') $candidates[] = $content;
    foreach ($candidates as $candidate) {
        if (preg_match('/data:image\/(png|jpe?g|webp|gif);base64,([A-Za-z0-9+\/\s=]+)/i', $candidate, $m)) {
            $ext = strtolower($m[1]);
            return ['b64' => preg_replace('/\s+/', '', $m[2]), 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
        }
        if (preg_match('/!\[[^\]]*\]\((https?:[^)\s]+)\)/', $candidate, $m)) {
            return ['url' => $m[1]];
        }
        if (preg_match('/https?:\/\/[^\s"\'<>]+\.(?:png|jpe?g|webp|gif)(?:\?[^\s"\'<>]*)?/i', $candidate, $m)) {
            return ['url' => $m[0]];
        }
        $trimmed = trim($candidate);
        if (preg_match('/^https?:\/\//i', $trimmed) && filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return ['url' => $trimmed];
        }
        $pure = preg_replace('/\s+/', '', $candidate);
        if (strlen($pure) > 1000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $pure)) {
            return ['b64' => $pure, 'ext' => 'png'];
        }
    }
    return null;
}

// 请求参数解析 支持JSON与GET
$rawInput = file_get_contents('php://input');
$input = json_decode((string) $rawInput, true);
if (!is_array($input)) $input = $_GET;

$modules = $config['modules'];
// 仅允许字母字符 防止模块名注入
$action  = preg_replace('/[^a-zA-Z]/', '', (string) ($input['action'] ?? 'default'));
if (!isset($modules[$action])) {
    respondError('未知的功能模块：' . ($action === '' ? 'default' : $action), 400, 'text');
}
$module = $modules[$action];

$type = strtolower(trim((string) ($input['type'] ?? 'text')));
if (!in_array($type, ['text', 'json'], true)) $type = 'text';

// 仅允许6位数字uid 防止路径穿越
$uid = trim((string) ($input['uid'] ?? $config['security']['default_uid']));
if (!preg_match('/^\d{6}$/', $uid)) $uid = $config['security']['default_uid'];

$question = trim((string) ($input['question'] ?? $input['msg'] ?? ''));

// 图像生成模块 统一模型端点 Base64解码保存后输出图片链接 无记忆
if ($action === 'image') {
    if ($question === '') respondError('请输入绘画提示词', 400, $type);
    if (mb_strlen($question) > $config['security']['max_question_length']) {
        respondError('提示词过长，请精简后重试', 400, $type);
    }
    $payload = [
        'model'    => $module['model'],
        'messages' => [['role' => 'user', 'content' => $question]],
    ];
    [$content, $err, $code, $message] = callChatApi($config, $payload, $module['timeout'] ?? null);
    if ($err) respondError($err, $code, $type);

    $imageData = extractImageFromMessage($content, is_array($message) ? $message : []);
    if ($imageData === null) {
        respondError('图像生成服务未返回有效图片，请换个描述重试', 502, $type);
    }
    // 上游直接返回图片链接时直接透传
    if (isset($imageData['url'])) {
        respond($imageData['url'], $type, ['uid' => $uid]);
    }

    $binary = base64_decode($imageData['b64'], true);
    if ($binary === false) {
        respondError('图片数据解码失败，请稍后重试', 502, $type);
    }
    if (strlen($binary) > $module['max_size']) {
        respondError('生成的图片超出大小限制，请稍后重试', 502, $type);
    }
    // 以真实图片内容校验并确定扩展名
    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        respondError('图片数据损坏，请稍后重试', 502, $type);
    }
    $mimeExt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext = $mimeExt[$info['mime']] ?? $imageData['ext'];

    $saveDir = $module['dir'];
    if (!is_dir($saveDir) && !mkdir($saveDir, 0755, true)) {
        respondError('图片存储目录创建失败，请稍后重试', 500, $type);
    }
    if (!is_writable($saveDir)) {
        respondError('图片存储目录不可写，请稍后重试', 500, $type);
    }
    $filename = uniqid('gen_', true) . '.' . $ext;
    if (file_put_contents($saveDir . $filename, $binary, LOCK_EX) === false) {
        respondError('图片保存失败，请稍后重试', 500, $type);
    }
    @chmod($saveDir . $filename, 0644);
    respond($module['base_url'] . $filename, $type, ['uid' => $uid]);
}

// 万能翻译模块 无记忆
if ($action === 'translate') {
    $languages = $config['languages'];
    $target = strtolower(trim((string) ($input['target'] ?? 'en')));
    if ($target === 'list') {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => true, 'languages' => $languages], JSON_UNESCAPED_UNICODE));
    }
    if ($question === '') respondError('请输入需要翻译的内容', 400, $type);
    if (isset($languages[$target])) {
        $targetName = $languages[$target];
    } elseif (($code = array_search($target, $languages, true)) !== false) {
        $targetName = $target;
    } else {
        respondError('暂不支持翻译该语言，请重新选择目标语言', 400, $type);
    }
    $payload = [
        'model'       => $module['model'],
        'messages'    => [
            ['role' => 'system', 'content' => '你是一个由云智计算训练和研发的专业翻译引擎。你的任务是将用户提供的文本精确地翻译成指定的语言。请直接输出翻译结果，不要添加任何前缀、后缀、解释、引号或格式符号。不要使用代码块格式或反引号包装翻译结果。'],
            ['role' => 'user', 'content' => "请将以下文本翻译成{$targetName}：{$question}"],
        ],
        'max_tokens'  => $module['max_tokens'],
        'temperature' => $module['temperature'],
    ];
    [$content, $err, $code] = callChatApi($config, $payload);
    if ($err) respondError($err, $code, $type);
    // 清理模型可能附加的包裹符号
    $content = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $content);
    if (strlen($content) >= 2) {
        $first = mb_substr($content, 0, 1);
        $last  = mb_substr($content, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $content = mb_substr($content, 1, -1);
        }
    }
    respond($content, $type, ['target' => $targetName]);
}

// 对话类模块参数校验
if ($question === '') respondError('请输入您的问题或需求', 400, $type);
if (mb_strlen($question) > $config['security']['max_question_length']) {
    respondError('输入内容过长，请精简后重试', 400, $type);
}

// 图片理解模块 多模态 共享记忆
if ($action === 'ocr') {
    $image = trim((string) ($input['image'] ?? ''));
    $video = trim((string) ($input['video'] ?? ''));
    if ($image !== '' && $video !== '') respondError('图片和视频只能同时传入一个', 400, $type);
    $mediaUrl = $image !== '' ? $image : $video;
    if ($mediaUrl === '' || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
        respondError('图片地址无效，请重新上传图片', 400, $type);
    }
    // 限制媒体地址白名单 防止SSRF
    $host = parse_url($mediaUrl, PHP_URL_HOST);
    $allowedHost = parse_url($config['upload']['base_url'], PHP_URL_HOST);
    if ($host === false || strcasecmp($host, $allowedHost) !== 0) {
        respondError('仅支持本站上传的图片地址', 400, $type);
    }
    $mediaType = $image !== '' ? 'image' : 'video';

    $memoryFile = memoryFileFor($action, $uid, $config);
    $history = loadHistory($memoryFile);
    $currentMessage = ['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $question],
        ['type' => $mediaType . '_url', $mediaType . '_url' => ['url' => $mediaUrl, 'detail' => 'high']],
    ]];
    $payload = [
        'model'      => $module['model'],
        'messages'   => array_merge($history, [$currentMessage]),
        'max_tokens' => $module['max_tokens'],
    ];
    [$content, $err, $code] = callChatApi($config, $payload);
    if ($err) respondError($err, $code, $type);
    // 多模态消息扁平化为文本存储 保证共享记忆可被纯文本模型复用
    $history[] = ['role' => 'user', 'content' => ($mediaType === 'image' ? '[图片] ' : '[视频] ') . $question];
    $history[] = ['role' => 'assistant', 'content' => $content];
    saveHistory($memoryFile, $history, $config['memory']['max_rounds']);
    respond($content, $type, ['uid' => $uid]);
}

// 通用对话模块 默认 思考 搜索 写作 解答 编程 需求优化
$messages = [];
if (!empty($module['system'])) {
    $messages[] = ['role' => 'system', 'content' => $module['system']];
}
$memoryFile = empty($module['no_memory']) ? memoryFileFor($action, $uid, $config) : null;
$history = $memoryFile ? loadHistory($memoryFile) : [];
if (!empty($history)) $messages = array_merge($messages, $history);
$messages[] = ['role' => 'user', 'content' => $question];

$payload = [
    'model'      => $module['model'],
    'messages'   => $messages,
    'max_tokens' => $module['max_tokens'],
];
if (isset($module['temperature'])) $payload['temperature'] = $module['temperature'];

[$content, $err, $code] = callChatApi($config, $payload);
if ($err) respondError($err, $code, $type);

if ($memoryFile) {
    $history[] = ['role' => 'user', 'content' => $question];
    $history[] = ['role' => 'assistant', 'content' => $content];
    saveHistory($memoryFile, $history, $config['memory']['max_rounds']);
}
respond($content, $type, ['uid' => $uid, 'module' => $action]);
