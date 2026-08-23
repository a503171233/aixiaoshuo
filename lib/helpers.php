<?php
declare(strict_types=1);

function kl_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false
        ? json_encode(['ok' => false, 'message' => '响应数据编码失败：' . json_last_error_msg()], JSON_UNESCAPED_UNICODE)
        : $json;
    exit;
}

function kl_fail(string $message, int $code = 400): void
{
    kl_json(['ok' => false, 'message' => $message], $code);
}

function kl_ok(array $data = []): void
{
    kl_json(['ok' => true] + $data);
}

function kl_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function kl_e(?string $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kl_secret_key(): string
{
    $cfg = kl_config();
    return (string)($cfg['app_key'] ?? 'klrvai-default-key');
}

/** API 密钥对称加密存储 */
function kl_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv = random_bytes(16);
    $key = hash('sha256', kl_secret_key(), true);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function kl_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $key = hash('sha256', kl_secret_key(), true);
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function kl_mask_key(string $stored): string
{
    $plain = kl_decrypt($stored);
    if ($plain === '') {
        return '';
    }
    $tail = substr($plain, -4);
    return str_repeat('*', max(8, min(24, strlen($plain) - 4))) . $tail;
}

function kl_user_id(): ?int
{
    kl_start_session();
    $id = $_SESSION['user_id'] ?? null;
    return $id ? (int)$id : null;
}

function kl_require_login(): int
{
    $id = kl_user_id();
    if (!$id) {
        kl_fail('未登录或登录状态已失效', 401);
    }
    return $id;
}

function kl_current_user(): ?array
{
    $id = kl_user_id();
    if (!$id) {
        return null;
    }
    return kl_one('SELECT * FROM {users} WHERE id = ?', [$id]);
}

function kl_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function kl_device(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '未知设备'), 0, 250);
}

/** 等级：按积分与字数综合计算 */
function kl_calc_level(int $points, int $words): int
{
    $score = $points + intdiv($words, 1000) * 10;
    foreach ([0, 500, 2000, 5000, 12000, 30000, 80000, 200000] as $i => $threshold) {
        if ($score < $threshold) {
            return max(1, $i);
        }
    }
    return 8;
}

function kl_format_words(int $words): string
{
    if ($words >= 10000) {
        $w = $words / 10000;
        return (fmod($w, 1.0) === 0.0 ? (string)(int)$w : number_format($w, 1)) . 'W';
    }
    return (string)$words;
}

function kl_random_code(int $len = 8): string
{
    return strtoupper(substr(bin2hex(random_bytes($len)), 0, $len));
}
