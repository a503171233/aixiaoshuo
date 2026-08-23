<?php
declare(strict_types=1);

define('KL_VERSION', '1.1.7');
define('KL_APP_NAME', 'KIrva 小说助手');
define('KL_ROOT', dirname(__DIR__));
define('KL_CONFIG_FILE', KL_ROOT . '/config/config.php');
define('KL_LOCK_FILE', KL_ROOT . '/config/install.lock');
define('KL_UPLOAD_DIR', KL_ROOT . '/uploads');
define('KL_DATA_DIR', KL_ROOT . '/data');

require_once KL_ROOT . '/lib/db.php';
require_once KL_ROOT . '/lib/helpers.php';
require_once KL_ROOT . '/lib/ai.php';

function kl_is_installed(): bool
{
    return is_file(KL_LOCK_FILE) && is_file(KL_CONFIG_FILE);
}

function kl_config(): array
{
    static $config = null;
    if ($config === null) {
        if (!is_file(KL_CONFIG_FILE)) {
            throw new RuntimeException('系统尚未安装，请先访问 install/index.php 完成安装。');
        }
        $config = require KL_CONFIG_FILE;
    }
    return $config;
}

function kl_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}
