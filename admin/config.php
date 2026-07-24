<?php
/**
 * 后台管理配置文件
 * 修改 ADMIN_PASSWORD_HASH 来更换密码
 * 运行: php -r "echo password_hash('你的密码', PASSWORD_BCRYPT);" 生成哈希
 */

// 后台密码 (bcrypt 哈希，默认密码: admin123)
define('ADMIN_PASSWORD_HASH', '$2y$10$d1zCt26nzWEJGdPEhw4iquMUoshjEUDhBfc4CEU0R.K5HbkJIZ3My');

// JWT 密钥 (请修改为随机字符串)
define('ADMIN_JWT_SECRET', 'yunzhi_admin_jwt_secret_' . hash('sha256', __DIR__));

// JWT 过期时间 (秒) - 默认 12 小时
define('ADMIN_JWT_EXPIRY', 43200);

// 后台基础路径 (部署时根据实际路径修改)
define('ADMIN_BASE', '/admin/');

// 项目根目录
define('PROJECT_ROOT', dirname(__DIR__));
