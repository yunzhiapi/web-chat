# 云智THREE AI模型在线对话 项目文档

> 示例站点：[yunzhiapi.cn/chat](https://yunzhiapi.cn/chat/)
> 版本：生产级版本 · 最后更新：2026-07-22

---

## 目录

1. [项目简介](#一项目简介)
2. [功能矩阵](#二功能矩阵)
3. [目录结构](#三目录结构)
4. [参数配置说明](#四参数配置说明)
5. [部署与运行](#五部署与运行)
6. [API接口文档](#六api接口文档)
7. [前端模块详解](#七前端模块详解)
8. [安全机制](#八安全机制)
9. [性能与SEO优化](#九性能与seo优化)
10. [常见问题FAQ](#十常见问题faq)
11. [疑难排错](#十一疑难排错)
12. [维护与清理](#十二维护与清理)

---

## 一、项目简介

云智THREE 是由云智计算训练和开发的多模态大模型在线对话系统，面向 Web 浏览器提供专业的 AI 辅助服务，覆盖智能写作、代码生成、图像创作、问题解答等场景。

项目采用 PHP + 原生 JavaScript 架构，无需 Node 构建工具链，部署简单，所有功能模块共用一套统一 API 端点，支持记忆对话、多模态输入、流式打字效果、代码高亮、Markdown 渲染等企业级特性。

### 技术栈

- 前端：HTML5 + 原生 JavaScript + Tailwind CSS（CDN）
- 后端：PHP 7.4+（需启用 curl、zlib、fileinfo 扩展）
- 依赖库：Font Awesome 6.4.0、highlight.js 11.7.0、marked 4.3.0
- 存储：本地文件系统（gzip 压缩 JSON 记忆文件）
- 上游模型：通过 OpenAI 兼容接口对接 GLM、DeepSeek、Qwen、GPT-OSS 等多模型

### 浏览器兼容性

- Chrome 90+ / Edge 90+ / Firefox 88+ / Safari 14+
- 需启用 JavaScript，需支持 fetch、AbortController、CSS backdrop-filter

---

## 二、功能矩阵

### 七大对话模式

| 模式 | 标识 | 上游模型 | 是否记忆 | 适用场景 |
| --- | --- | --- | --- | --- |
| 默认对话 | default | GLM-Z1-0414 | 共享记忆 | 通用问答、闲聊 |
| 深度思考 | thinking | DeepSeek-R1 | 共享记忆 | 复杂推理、逻辑分析 |
| 联网搜索 | websearch | DeepSeek-V4-Flash | 共享记忆 | 实时信息查询 |
| 图像生成 | image | GPT-Image-2 | 无记忆 | 文生图创作 |
| 帮我写作 | writing | Agnes-2.0-Flash | 共享记忆 | 文体创作、润色 |
| 搜题解答 | answer | GPT-OSS-120B | 共享记忆 | 学科题目讲解 |
| 万能翻译 | translate | GLM-Z1-0414 | 无记忆 | 多语言互译（80+语种） |
| 代码编程 | code | DeepSeek-V4-Flash | 独立记忆 | 代码优化、需求分析 |

### 共享记忆机制

以下模块共享同一份对话记忆，切换模式后模型仍能延续上下文：
- 默认对话、深度思考、联网搜索、搜题解答、帮我写作、图片理解

代码编程模式使用独立的记忆文件，避免代码上下文污染通用对话。

### 辅助功能

- **图片上传与理解**：支持 JPG/PNG/GIF/WEBP/BMP/TIFF/PDF，最大 10MB，模型基于图片内容回答
- **代码文件上传**：支持 30+ 编程语言文件，可在线编辑后提交优化
- **流式打字效果**：逐字符渲染 AI 回复，支持停止生成
- **代码高亮**：自动识别语言并高亮显示，一键复制
- **Markdown 渲染**：支持表格、列表、引用、链接等完整语法
- **语音播放**：使用浏览器原生 SpeechSynthesis 朗读 AI 回复
- **消息编辑**：可编辑历史用户消息并重新生成回复
- **重试生成**：对 AI 回复不满意时可重新生成
- **点赞反馈**：对回复进行点赞/点踩评价
- **智能滚动**：自动跟随滚动或一键回到顶部/底部
- **响应式布局**：完美适配桌面、平板、手机
- **无障碍支持**：ARIA 标签、键盘操作、减少动画偏好

---

## 三、目录结构

```
云智/
├── index.html              前端页面（HTML+CSS+JS 单文件）
├── api.php                 统一API端点（所有功能模块请求转发）
├── upload.php              图片上传处理（含安全校验）
├── config.php              全局配置（端点/密钥/模型/记忆/上传/安全）
├── clean.php               记忆清理脚本（命令行或定时任务调用）
├── 文档.md                  本文档
├── favicon.ico             站点图标
├── chat.webp               AI助手头像
├── user.webp               用户头像
└── file/                   运行时目录（自动创建）
    ├── *.json.gz           对话记忆文件（gzip压缩JSON）
    ├── img_*.jpg           用户上传的图片
    ├── gen_*.png           AI生成的图片
    ├── image/              AI生成图片存储子目录
    ├── code/               代码编程模式记忆子目录
    ├── ratelimit/          限流计数文件
    ├── log/                错误与安全日志
    └── upload.log          上传记录日志
```

---

## 四、参数配置说明

所有配置集中在 `config.php`，修改后无需重启服务即可生效。

### 1. API 配置（api 节）

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| url | string | https://yunzhiapi.cn/v1/chat/completions | 上游模型API地址（OpenAI兼容格式） |
| key | string | sk-xxx | 上游API密钥（生产环境建议改用环境变量） |
| timeout | int | 120 | 请求超时秒数 |
| connect_timeout | int | 15 | 连接超时秒数 |

### 2. 安全配置（security 节）

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| allowed_origin | string | https://yunzhiapi.cn | 允许的来源（CORS白名单） |
| default_uid | string | 000000 | 默认用户ID（6位数字） |
| max_question_length | int | 30000 | 单次输入最大字符数 |
| clean_token | string | 空 | 清理脚本访问令牌，为空则不校验 |
| rate_limit.enabled | bool | true | 是否启用限流 |
| rate_limit.window | int | 60 | 限流时间窗口（秒） |
| rate_limit.max_reqs | int | 30 | 窗口内最大请求数（上传单独按1/6计算） |
| rate_limit.dir | string | __DIR__/file/ratelimit/ | 限流计数文件目录 |
| log.enabled | bool | true | 是否启用错误日志 |
| log.dir | string | __DIR__/file/log/ | 日志目录 |
| log.retention_days | int | 7 | 日志保留天数 |

### 3. 记忆配置（memory 节）

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| dir | string | __DIR__/file/ | 共享记忆存储目录 |
| code_dir | string | __DIR__/file/code/ | 代码模式独立记忆目录 |
| max_rounds | int | 30 | 最大记忆轮数（实际保留 2×max_rounds 条消息） |
| shared_modules | array | 见配置 | 共享记忆的模块列表 |

### 4. 上传配置（upload 节）

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| dir | string | __DIR__/file/ | 上传文件存储目录 |
| base_url | string | https://yunzhiapi.cn/chat/file/ | 上传文件访问URL前缀 |
| max_size | int | 10485760 | 最大文件大小（字节，默认10MB） |
| allowed_ext | array | jpg/jpeg/png/gif/webp/bmp/tif/tiff/pdf | 允许的扩展名白名单 |

### 5. 模块配置（modules 节）

每个模块支持以下字段：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| model | string | 是 | 上游模型名称 |
| max_tokens | int | 否 | 最大输出token数 |
| system | string | 否 | 系统提示词 |
| temperature | float | 否 | 采样温度（0-2） |
| timeout | int | 否 | 自定义请求超时（仅 image 模块） |
| no_memory | bool | 否 | 是否禁用记忆（translate/image/codeOptimize 默认禁用） |
| multimodal | bool | 否 | 是否为多模态模块（ocr） |
| dir | string | 否 | 文件存储目录（仅 image） |
| base_url | string | 否 | 文件访问URL前缀（仅 image） |
| max_size | int | 否 | 生成图片最大字节数（仅 image） |

### 6. 语言配置（languages 节）

键值对形式，键为语言代码（如 en、zh-cn），值为中文名称。共支持 80+ 语种，包括主流语言与小语种。修改后无需重启即可生效。

---

## 五、部署与运行

### 环境要求

- PHP 7.4 或更高版本
- 必须启用的扩展：curl、zlib、fileinfo、mbstring
- Web 服务器：Nginx + PHP-FPM 或 Apache + mod_php
- HTTPS 证书（推荐 Let's Encrypt 免费证书）

### 部署步骤

1. **上传源码**：将所有文件上传到 Web 服务器根目录或 `/chat/` 子目录
2. **配置权限**：确保 `file/` 目录可读写
   ```bash
   mkdir -p file/code file/image file/ratelimit file/log
   chmod -R 755 file/
   chmod 644 *.php *.html
   ```
3. **修改配置**：编辑 `config.php`，修改以下关键字段
   - `api.key`：替换为您的上游API密钥
   - `security.allowed_origin`：替换为您的站点域名
   - `upload.base_url`：替换为您的文件访问URL前缀
   - `modules.image.base_url`：替换为生成图片的访问URL前缀
4. **配置 Nginx**（推荐配置）
   ```nginx
   location /chat/ {
       try_files $uri $uri/ /chat/index.html;
   }
   location ~ \.php$ {
       fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
       fastcgi_index index.php;
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
   }
   # 禁止访问敏感文件
   location ~ /file/.*\.(json\.gz|log)$ {
       deny all;
       return 403;
   }
   location ~ /config\.php {
       deny all;
       return 403;
   }
   ```
5. **配置 HTTPS**：强制 HTTPS 跳转
6. **测试访问**：访问 `https://your-domain/chat/` 验证页面加载正常

### 生产环境加固建议

- 将 `config.php` 中的 `api.key` 改为从环境变量读取
- 将 `file/` 目录移出 Web 可访问目录，通过 PHP 转发访问
- 配置定时任务定期清理过期记忆与日志
- 启用 OPcache 提升 PHP 性能
- 配置 CDN 加速静态资源

---

## 六、API接口文档

所有请求统一指向 `api.php`，采用 POST 方法，Content-Type 为 `application/json`。

### 1. 通用对话接口

**请求体：**
```json
{
  "action": "default",
  "question": "您好",
  "uid": "123456",
  "type": "text"
}
```

**参数说明：**

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| action | string | 是 | 模块名：default/thinking/websearch/writing/answer/code/codeOptimize/ocr/image/translate |
| question | string | 是 | 用户输入（image模块用 msg 字段） |
| uid | string | 否 | 6位数字用户ID，默认 000000 |
| type | string | 否 | 响应格式：text（默认）或 json |
| msg | string | 否 | image/translate模块的提示词字段（替代question） |
| image | string | 否 | ocr模块的图片URL |
| video | string | 否 | ocr模块的视频URL（与image互斥） |
| target | string | 否 | translate模块的目标语言代码，传 list 获取语言列表 |

**响应（type=text）：**
```
纯文本内容
```

**响应（type=json）：**
```json
{
  "success": true,
  "content": "回复内容",
  "uid": "123456",
  "module": "default"
}
```

**错误响应：**
```
HTTP 400/403/429/500/502
错误描述文本
```

### 2. 图片上传接口

**请求：** POST `upload.php`，Content-Type 为 `multipart/form-data`

**表单字段：**
- `image`：图片文件

**成功响应：**
```json
{
  "success": true,
  "message": "文件上传成功",
  "filename": "img_xxx.jpg",
  "url": "https://yunzhiapi.cn/chat/file/img_xxx.jpg"
}
```

**失败响应：**
```json
{
  "success": false,
  "message": "错误描述"
}
```

### 3. 记忆清理接口

**请求：** GET `clean.php?token=您的清理令牌`

如果 `config.php` 中 `clean_token` 为空，则不校验 token。响应为纯文本格式，列出删除的文件清单。

---

## 七、前端模块详解

### 核心状态变量

| 变量 | 类型 | 说明 |
| --- | --- | --- |
| conversationHistory | array | 当前对话历史（含 question/answer/attachment） |
| currentMode | string|null | 当前激活的模式 |
| codeModeActive | bool | 是否处于代码编程模式 |
| codeModeStep | int | 代码模式步骤（1=上传文件，2=输入需求，3=生成代码） |
| isSubmitting | bool | 是否正在请求中 |
| typingEffectActive | bool | 是否正在打字效果中 |
| memoryId | string | 6位数字会话ID |
| uploadedImage | string|null | 已上传图片URL |
| uploadedFiles | array | 已上传代码文件列表 |

### 代码编程模式流程

1. **步骤1**：用户上传代码文件 → 系统读取文件内容并显示卡片，支持在线编辑
2. **步骤2**：用户输入优化需求 → 调用 codeOptimize 模块生成结构化需求描述，用户可修改后确认
3. **步骤3**：系统合并文件内容与需求 → 调用 code 模块生成优化后的代码

整个流程中输入框会被禁用，直到完成或退出模式。

### 流式打字效果实现

`typeWriterEffect` 函数将回复内容按文本块和代码块分段渲染：
- 文本块：逐字符添加，完成后渲染为 Markdown
- 代码块：逐字符添加到 `<code>` 标签，完成后调用 highlight.js 高亮
- 支持 `prefers-reduced-motion` 偏好，开启时一次性渲染

---

## 八、安全机制

### 1. 来源校验

- `api.php` 与 `upload.php` 均校验 `Origin` 和 `Referer` 头
- 仅允许 `config.security.allowed_origin` 配置的域名访问
- 非法来源返回 403 并记录日志

### 2. 限流保护

- 基于 IP 的简单限流，使用文件计数器实现
- 默认 60 秒窗口内最多 30 次请求
- 上传接口使用更严格的阈值（默认 5 次/60秒）
- 触发限流返回 429 并提示 `Retry-After`

### 3. 输入校验

- 模块名仅允许字母字符（防注入）
- uid 严格校验为 6 位数字（防路径穿越）
- 输入长度限制 30000 字符（前后端双校验）
- OCR 模块限制图片URL必须为本站域名（防SSRF）

### 4. 文件上传安全

- 扩展名白名单校验
- 真实 MIME 类型校验（finfo）
- 扩展名与 MIME 交叉校验（防伪装）
- 图片完整性校验（getimagesize）
- 文件名长度限制
- 随机生成存储文件名（防碰撞与路径泄露）
- 存储目录不可执行（需 Web 服务器配置）

### 5. 响应安全头

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: same-origin`
- `Content-Security-Policy`（建议在 Web 服务器层配置）

### 6. 数据安全

- API 密钥存储在 PHP 文件中，不直接暴露给前端
- 记忆文件使用 gzip 压缩，无法直接读取
- 上传日志仅记录 IP、文件名、大小、MIME，不记录敏感信息
- 日志自动清理，默认保留 7 天

---

## 九、性能与SEO优化

### 加载速度优化

1. **资源预加载**：对 Font Awesome、highlight.js 主题、头像图片使用 `preload`
2. **DNS预解析**：对 CDN 域名使用 `dns-prefetch`
3. **预连接**：对 CDN 域名使用 `preconnect` 减少 TLS 握手
4. **延迟加载**：highlight.js、marked.js 使用 `defer` 异步加载
5. **图片懒加载**：所有 `<img>` 标签使用 `loading="lazy"` 和 `decoding="async"`
6. **内容可见性**：消息容器使用 `content-visibility: auto` 优化长列表渲染
7. **滚动节流**：使用 `requestAnimationFrame` 节流滚动事件
8. **前端重试**：网络异常时自动重试 2 次，指数退避

### SEO优化

1. **结构化数据**：使用 JSON-LD 标注 `WebApplication` 与 `BreadcrumbList`
2. **完整 meta 标签**：description、keywords、og、twitter card、robots
3. **canonical 标签**：避免重复内容惩罚
4. **语义化HTML**：使用 `aria-label`、`aria-hidden` 等无障碍属性
5. **站点验证**：预留 Google、Baidu 站点验证码位置
6. **多语言声明**：`og:locale` 声明 zh_CN
7. **noscript 降级**：未启用JS时显示友好提示

### 进一步优化建议

- 配置 CDN 加速静态资源
- 启用 HTTP/2 与 Brotli 压缩
- 配置 Service Worker 缓存
- 为 `chat.webp`、`user.webp` 配置长缓存
- 上传 `sitemap.xml` 与 `robots.txt` 到站点根目录

---

## 十、常见问题FAQ

### Q1：页面打开后无法发送消息？

**A：** 请按以下顺序排查：
1. 检查浏览器是否启用 JavaScript
2. 打开开发者工具查看 Console 是否有报错
3. 检查 Network 面板 `api.php` 请求是否返回 403（来源校验失败）
4. 确认 `config.php` 中 `allowed_origin` 与实际访问域名完全一致（含 https://）

### Q2：AI回复一直转圈不出结果？

**A：** 可能原因：
- 上游模型服务超时，默认 120 秒，可在 `config.api.timeout` 调整
- 网络异常，前端会在 120 秒后自动超时
- 上游返回空内容，前端会提示"模型未返回有效内容"
- 可点击"停止生成"按钮中止请求

### Q3：图片上传失败提示"非法请求来源"？

**A：** 上传接口同样校验来源。请确认：
1. 通过页面中的上传按钮访问，而非直接调用接口
2. `config.security.allowed_origin` 与访问域名完全一致
3. 浏览器未禁用 Referer（隐私模式下可能丢失）

### Q4：代码编程模式无法退出？

**A：** 代码编程模式下输入框会被禁用。退出方式：
1. 点击"代码编程"模式按钮，弹出确认对话框
2. 点击"确认退出"即可退出，当前进度会被清除

### Q5：翻译结果不正确？

**A：** 翻译模块使用专门的低温度（0.1）配置，但仍可能受模型能力限制。建议：
1. 输入完整的待翻译文本
2. 明确选择目标语言（点击左侧按钮选择）
3. 避免输入含歧义的多语言混合文本

### Q6：图像生成返回的是链接而非图片？

**A：** 图像生成支持两种返回格式：
1. 上游直接返回图片URL → 前端直接显示
2. 上游返回 Base64 → 后端解码保存为文件，返回文件URL
若上游返回格式异常，会提示"图像生成服务未返回有效图片"

### Q7：刷新页面后对话记录丢失？

**A：** 本项目对话记忆存储在服务端（基于 uid），但前端 `conversationHistory` 仅存在于当前会话。刷新页面会重新生成 uid，因此显示为新对话。如需保留同一会话，可在 URL 中拼接 uid（需自行实现）。

### Q8：移动端输入框字体很小？

**A：** 已针对移动端优化，输入框 `font-size: 16px` 可避免 iOS 自动缩放。如仍异常，请检查浏览器缩放设置。

### Q9：语音播放没有声音？

**A：** 语音播放使用浏览器原生 `SpeechSynthesis` API：
1. 确认浏览器支持（Chrome、Edge、Safari 支持，Firefox 部分支持）
2. 确认系统已安装中文语音包
3. 检查浏览器是否已静音
4. 移动端需要用户交互后才能播放（点击按钮即满足）

### Q10：限流提示"请求过于频繁"？

**A：** 默认限制 60 秒内 30 次请求。如为正常使用触发，可在 `config.security.rate_limit.max_reqs` 调整阈值。如为恶意触发，建议保留限流并配合 Nginx 层防护。

---

## 十一、疑难排错

### 1. 后端排查

#### 1.1 查看 PHP 错误日志
```bash
# 默认位置（根据 php.ini 配置）
tail -f /var/log/php/error.log
# 或项目日志目录
tail -f file/log/$(date +%Y-%m-%d).log
```

#### 1.2 测试 API 连通性
```bash
curl -X POST https://your-domain/chat/api.php \
  -H "Origin: https://your-domain" \
  -H "Content-Type: application/json" \
  -d '{"action":"default","question":"测试","uid":"000000"}'
```

#### 1.3 验证 PHP 扩展
```bash
php -m | grep -E "curl|zlib|fileinfo|mbstring"
```

### 2. 常见错误对照表

| 错误现象 | 可能原因 | 解决方案 |
| --- | --- | --- |
| 403 违规拦截 | 来源校验失败 | 检查 `allowed_origin` 配置与访问域名 |
| 405 仅支持POST或GET | 请求方法错误 | 改用 POST 请求 |
| 429 请求过于频繁 | 触发限流 | 等待窗口期或调整限流配置 |
| 500 目录创建失败 | file 目录无写权限 | `chmod -R 755 file/` |
| 502 AI服务连接失败 | 上游API不可达或密钥失效 | 检查 api.url 与 api.key |
| 模型未返回有效内容 | 上游返回空 | 换个问法或稍后重试 |
| 图片地址无效 | OCR 图片URL格式错误 | 确认URL为合法 http/https 链接 |
| 仅支持本站上传的图片地址 | SSRF 防护拦截 | 确保图片URL域名与 base_url 一致 |

### 3. 前端排查

#### 3.1 Console 错误
- `marked is not defined`：CDN 加载失败，检查网络
- `hljs is not defined`：同上
- `Failed to fetch`：网络异常或 CORS 失败

#### 3.2 Network 面板
- 检查 `api.php` 请求的状态码与响应内容
- 检查 `upload.php` 请求的响应
- 确认 OPTIONS 预检请求返回 204

#### 3.3 记忆文件损坏恢复
```bash
# 备份后删除损坏的记忆文件
mv file/000000.json.gz file/000000.json.gz.bak
# 或直接清理所有记忆（谨慎操作）
php clean.php
```

### 4. 性能排查

#### 4.1 上游响应慢
- 查看 `file/log/` 日志中的上游HTTP错误
- 在 `config.api.timeout` 增加超时时间
- 联系上游服务提供商确认服务状态

#### 4.2 文件IO慢
- 检查 `file/` 目录文件数量，过多时影响扫描
- 定期执行 `clean.php` 清理过期记忆
- 考虑迁移到 SSD 存储

#### 4.3 限流计数文件堆积
```bash
# 清理过期的限流文件（保留最近1小时）
find file/ratelimit/ -type f -mmin +60 -delete
```

---

## 十二、维护与清理

### 1. 定时清理任务

建议配置 crontab 定时清理：

```bash
# 每天凌晨3点清理过期记忆（需配置 clean_token）
0 3 * * * curl -s "https://your-domain/chat/clean.php?token=YOUR_TOKEN" > /dev/null 2>&1

# 每小时清理过期限流文件
0 * * * * find /path/to/file/ratelimit/ -type f -mmin +120 -delete

# 每周清理过期日志
0 4 * * 0 find /path/to/file/log/ -type f -mtime +7 -delete
```

### 2. 备份策略

- **配置备份**：`config.php` 修改后立即备份
- **记忆备份**：定期备份 `file/*.json.gz`（按业务需求）
- **图片备份**：定期备份 `file/image/` 与 `file/img_*`
- **日志归档**：按月归档 `file/log/` 到对象存储

### 3. 升级注意事项

- 升级前备份 `config.php` 与 `file/` 目录
- 升级 `index.html`、`api.php`、`upload.php`、`clean.php`
- 比对 `config.php` 是否有新增配置项
- 清空浏览器缓存与 OPcache
- 验证所有功能模块正常工作

### 4. 监控建议

- 监控 `file/log/` 中的错误率
- 监控 `api.php` 响应时间
- 监控 `file/ratelimit/` 触发频率
- 监控磁盘空间使用情况
- 配置上游API可用性监控

---

## 附录：版本说明

- **当前版本**：生产级版本
- **架构**：PHP 单文件后端 + 原生JS单文件前端
- **依赖**：Tailwind CSS、Font Awesome、highlight.js、marked（均通过CDN加载）
- **许可**：云智计算内部使用
- **示例站点**：[yunzhiapi.cn/chat](https://yunzhiapi.cn/chat/)

如遇本文档未覆盖的问题，请先查看 `file/log/` 日志，再结合浏览器开发者工具排查。所有错误均会记录到服务端日志，便于事后分析。
