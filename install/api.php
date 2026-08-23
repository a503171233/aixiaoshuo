<?php
declare(strict_types=1);

define('KL_ROOT', dirname(__DIR__));
require_once KL_ROOT . '/lib/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    $json = json_decode((string)file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

if (is_file(KL_ROOT . '/config/install.lock')) {
    respond(['ok' => false, 'message' => '系统已安装完成。若需重新安装，请手动删除 config/install.lock 文件（请先备份现有数据）。'], 403);
}

$action = $_GET['action'] ?? '';
$in = input();

function build_db_config(array $in): array
{
    $driver = ($in['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
    $prefix = trim((string)($in['prefix'] ?? 'kl_'));
    if ($prefix === '' || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        respond(['ok' => false, 'message' => '数据表前缀只能包含字母、数字与下划线'], 422);
    }
    if ($driver === 'sqlite') {
        return [
            'driver' => 'sqlite',
            'database' => KL_ROOT . '/data/klrvai_novel.sqlite',
            'prefix' => $prefix,
        ];
    }
    return [
        'driver' => 'mysql',
        'host' => trim((string)($in['host'] ?? '127.0.0.1')) ?: '127.0.0.1',
        'port' => (int)($in['port'] ?? 3306) ?: 3306,
        'database' => trim((string)($in['database'] ?? 'klrvai_novel')),
        'username' => trim((string)($in['username'] ?? 'root')),
        'password' => (string)($in['password'] ?? ''),
        'prefix' => $prefix,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];
}

function friendly_db_error(PDOException $e): string
{
    $msg = $e->getMessage();
    return match (true) {
        str_contains($msg, 'Access denied') => '数据库账号或密码错误（Access denied for user）',
        str_contains($msg, 'Unknown database') => '指定的数据库不存在（Unknown database），可勾选自动创建数据库',
        str_contains($msg, 'Connection refused') => '数据库连接被拒绝，请确认 MySQL 服务已启动、主机与端口正确',
        str_contains($msg, 'timed out') || str_contains($msg, 'timeout') => '数据库连接超时，请检查网络与防火墙设置',
        str_contains($msg, 'unable to open database') => '无法打开数据库文件，请确认 data 目录可写',
        default => '数据库连接失败：' . $msg,
    };
}

function existing_tables(PDO $pdo, array $cfg): array
{
    $prefix = $cfg['prefix'];
    if ($cfg['driver'] === 'sqlite') {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    } else {
        $stmt = $pdo->query('SHOW TABLES');
    }
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        if (str_starts_with((string)$row[0], $prefix)) {
            $tables[] = (string)$row[0];
        }
    }
    return $tables;
}

if ($action === 'test') {
    $cfg = build_db_config($in);
    $autoCreate = !empty($in['auto_create']);
    if ($cfg['driver'] === 'mysql' && $cfg['database'] === '') {
        respond(['ok' => false, 'message' => '请填写数据库名称'], 422);
    }
    try {
        try {
            $pdo = kl_connect($cfg);
        } catch (PDOException $e) {
            if ($cfg['driver'] === 'mysql' && $autoCreate && str_contains($e->getMessage(), 'Unknown database')) {
                $server = $cfg;
                $server['database'] = '';
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $server['host'], $server['port']);
                $root = new PDO($dsn, $server['username'], $server['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $root->exec(sprintf(
                    'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $cfg['database'])
                ));
                $pdo = kl_connect($cfg);
            } else {
                throw $e;
            }
        }
        $tables = existing_tables($pdo, $cfg);
        $_SESSION['kl_install_db'] = $cfg;
        respond([
            'ok' => true,
            'message' => $cfg['driver'] === 'sqlite'
                ? 'SQLite 演示数据库可用：data/klrvai_novel.sqlite'
                : '数据库连接成功：' . $cfg['database'],
            'tables' => $tables,
        ]);
    } catch (PDOException $e) {
        respond(['ok' => false, 'message' => friendly_db_error($e)], 422);
    } catch (Throwable $e) {
        respond(['ok' => false, 'message' => '创建数据库失败：' . $e->getMessage()], 422);
    }
}

if ($action === 'import') {
    $cfg = $_SESSION['kl_install_db'] ?? null;
    if (!is_array($cfg)) {
        respond(['ok' => false, 'message' => '数据库配置已失效，请返回上一步重新测试连接'], 422);
    }
    $mode = ($in['mode'] ?? 'keep') === 'overwrite' ? 'overwrite' : 'keep';
    $adminUser = trim((string)($in['admin_user'] ?? ''));
    $adminPass = (string)($in['admin_pass'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $adminUser)) {
        respond(['ok' => false, 'message' => '管理员用户名需为 2-20 位中英文、数字或下划线'], 422);
    }
    if (strlen($adminPass) < 6) {
        respond(['ok' => false, 'message' => '管理员密码至少 6 位'], 422);
    }

    $sqlFile = __DIR__ . ($cfg['driver'] === 'sqlite' ? '/install.sqlite.sql' : '/install.sql');
    if (!is_file($sqlFile) || !is_readable($sqlFile)) {
        respond(['ok' => false, 'message' => 'SQL 文件不存在或无法读取：' . basename($sqlFile)], 500);
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        respond(['ok' => false, 'message' => 'SQL 文件读取失败，请检查文件权限'], 500);
    }
    if ($cfg['prefix'] !== 'kl_') {
        $sql = str_replace('`kl_', '`' . $cfg['prefix'], $sql);
    }

    try {
        $pdo = kl_connect($cfg);
    } catch (PDOException $e) {
        respond(['ok' => false, 'message' => friendly_db_error($e)], 422);
    }

    $existing = existing_tables($pdo, $cfg);
    if ($mode === 'overwrite' && $existing) {
        if ($cfg['driver'] === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($existing as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . ($cfg['driver'] === 'mysql' ? '`' . $table . '`' : '"' . $table . '"'));
        }
        if ($cfg['driver'] === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $existing = [];
    }

    $statements = kl_split_sql($sql);
    $executed = 0;
    $skipped = 0;
    foreach ($statements as $index => $statement) {
        $isInsert = stripos(ltrim($statement), 'INSERT') === 0;
        if ($mode === 'keep' && $isInsert && $existing) {
            preg_match('/INSERT INTO [`"]?([A-Za-z0-9_]+)/', $statement, $m);
            if (!empty($m[1]) && in_array($m[1], $existing, true)) {
                $skipped++;
                continue;
            }
        }
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            respond([
                'ok' => false,
                'message' => sprintf(
                    'SQL 第 %d 条语句执行失败：%s；语句片段：%s',
                    $index + 1,
                    $e->getMessage(),
                    mb_substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 120)
                ),
            ], 500);
        }
    }

    $prefix = $cfg['prefix'];
    $now = date('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("SELECT id FROM {$prefix}users WHERE username = ?");
        $stmt->execute([$adminUser]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}users (username,password_hash,points,level,invited_count,invite_limit,invite_code,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $adminUser,
                password_hash($adminPass, PASSWORD_DEFAULT),
                0, 1, 0, 30,
                strtoupper(substr(bin2hex(random_bytes(6)), 0, 8)),
                $now, $now,
            ]);
            $userId = (int)$pdo->lastInsertId();
        }
        $stmt = $pdo->prepare("SELECT user_id FROM {$prefix}model_configs WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}model_configs (user_id,provider,api_base,api_key,model,temperature,max_tokens,system_prompt,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $userId, '自定义 OpenAI 兼容', '', '', '', 0.7, 129000,
                '你是一位资深网络小说编辑与作者，擅长结构化输出。', $now,
            ]);
        }
        $pdo->prepare("UPDATE {$prefix}mcp_plugins SET user_id = ? WHERE user_id = 0")->execute([$userId]);
    } catch (PDOException $e) {
        respond(['ok' => false, 'message' => '创建管理员账户失败：' . $e->getMessage()], 500);
    }

    $_SESSION['kl_install_imported'] = true;
    $_SESSION['kl_install_admin'] = $adminUser;
    respond([
        'ok' => true,
        'message' => sprintf('导入完成：执行 %d 条语句，跳过 %d 条，管理员账户为 %s', $executed, $skipped, $adminUser),
        'executed' => $executed,
        'skipped' => $skipped,
    ]);
}

if ($action === 'finish') {
    $cfg = $_SESSION['kl_install_db'] ?? null;
    if (!is_array($cfg) || empty($_SESSION['kl_install_imported'])) {
        respond(['ok' => false, 'message' => '请先完成数据库导入'], 422);
    }
    $configDir = KL_ROOT . '/config';
    if (!is_dir($configDir) && !@mkdir($configDir, 0777, true)) {
        respond(['ok' => false, 'message' => 'config 目录不存在且无法创建，请检查目录权限'], 500);
    }
    if (!is_writable($configDir)) {
        respond(['ok' => false, 'message' => 'config 目录不可写，无法写入配置文件，请赋予写权限'], 500);
    }
    $config = [
        'app_name' => 'KIrva 小说助手',
        'version' => '1.1.7',
        'app_key' => bin2hex(random_bytes(16)),
        'installed_at' => date('Y-m-d H:i:s'),
        'update_check_url' => 'https://example.com/klrvai/version.json',
        'db' => $cfg,
    ];
    $php = "<?php\n// KIrva 小说助手 配置文件，由安装向导自动生成\nreturn " . var_export($config, true) . ";\n";
    if (@file_put_contents($configDir . '/config.php', $php) === false) {
        respond(['ok' => false, 'message' => '配置文件写入失败，可能磁盘空间不足或权限不足'], 500);
    }
    if (@file_put_contents($configDir . '/install.lock', date('Y-m-d H:i:s')) === false) {
        respond(['ok' => false, 'message' => '安装锁文件写入失败，请检查 config 目录权限'], 500);
    }
    unset($_SESSION['kl_install_db'], $_SESSION['kl_install_imported']);
    respond(['ok' => true, 'message' => '安装完成，配置文件与安装锁文件已生成']);
}

respond(['ok' => false, 'message' => '未知的安装操作'], 404);
