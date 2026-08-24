<?php
declare(strict_types=1);

function kl_dsn(array $cfg): string
{
    if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
        return 'sqlite:' . $cfg['database'];
    }
    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['database'],
        $cfg['charset'] ?? 'utf8mb4'
    );
}

function kl_connect(array $cfg): PDO
{
    $pdo = new PDO(kl_dsn($cfg), $cfg['username'] ?? '', $cfg['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

// 支持运行时动态切换数据库配置
function kl_set_runtime_db(array $cfg): void
{
    // 将运行时数据库配置保存到静态变量，供后续 kl_db 使用
    static $runtime_cfg = null;
    $runtime_cfg = $cfg;
    // 重新建立连接，覆盖已有 PDO 实例
    $GLOBALS['__kl_runtime_db_cfg'] = $cfg;
    // 清除已缓存的 PDO 实例，以便下次重新连接
    $GLOBALS['__kl_pdo_instance'] = null;
}

function kl_db(): PDO
{
    // 使用全局缓存的 PDO 实例，实现单例
    if (isset($GLOBALS['__kl_pdo_instance']) && $GLOBALS['__kl_pdo_instance'] instanceof PDO) {
        return $GLOBALS['__kl_pdo_instance'];
    }
    // 优先使用运行时配置，否则使用配置文件中的 db 配置
    $cfg = $GLOBALS['__kl_runtime_db_cfg'] ?? null;
    if ($cfg === null) {
        $cfg = kl_config();
        $cfg = $cfg['db'];
    }
    $GLOBALS['__kl_pdo_instance'] = kl_connect($cfg);
    return $GLOBALS['__kl_pdo_instance'];
}

function kl_prefix(): string
{
    return kl_config()['db']['prefix'] ?? 'kl_';
}

function kl_driver(): string
{
    return kl_config()['db']['driver'] ?? 'mysql';
}

function kl_table(string $name): string
{
    return kl_prefix() . $name;
}

/** 将 SQL 中的 {表名} 占位符替换为带前缀的真实表名 */
function kl_sql(string $sql): string
{
    return preg_replace_callback('/\{(\w+)\}/', static fn($m) => kl_table($m[1]), $sql);
}

function kl_query(string $sql, array $params = []): PDOStatement
{
    $stmt = kl_db()->prepare(kl_sql($sql));
    $stmt->execute($params);
    return $stmt;
}

function kl_all(string $sql, array $params = []): array
{
    return kl_query($sql, $params)->fetchAll();
}

function kl_one(string $sql, array $params = []): ?array
{
    $row = kl_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function kl_value(string $sql, array $params = [], $default = null)
{
    $row = kl_query($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? $default : $row[0];
}

function kl_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        kl_table($table),
        implode(',', array_map(static fn($c) => "`$c`", $cols)),
        implode(',', array_fill(0, count($cols), '?'))
    );
    $stmt = kl_db()->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)kl_db()->lastInsertId();
}

function kl_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = implode(',', array_map(static fn($c) => '`' . $c . '`=?', array_keys($data)));
    $sql = sprintf('UPDATE %s SET %s WHERE %s', kl_table($table), $sets, $where);
    $stmt = kl_db()->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $whereParams));
    return $stmt->rowCount();
}

function kl_delete(string $table, string $where, array $params = []): int
{
    $stmt = kl_db()->prepare(sprintf('DELETE FROM %s WHERE %s', kl_table($table), $where));
    $stmt->execute($params);
    return $stmt->rowCount();
}

function kl_now(): string
{
    return date('Y-m-d H:i:s');
}

/** 按分号切分 SQL 脚本，忽略注释行 */
function kl_split_sql(string $sql): array
{
    $lines = preg_split('/\r\n|\n|\r/', $sql) ?: [];
    $buffer = '';
    $statements = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = rtrim(trim($buffer), ';');
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = rtrim(trim($buffer), ';');
    }
    return $statements;
}
