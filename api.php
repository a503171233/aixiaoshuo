<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!kl_is_installed()) {
    kl_json(['ok' => false, 'message' => '系统尚未安装，请先访问 install/index.php 完成安装'], 503);
}

kl_start_session();
$action = (string)($_GET['action'] ?? '');
$in = kl_input();

$publicActions = ['login', 'register', 'session', 'db_switch'];
if (!in_array($action, $publicActions, true)) {
    kl_require_login();
}

switch ($action) {
    /* ---------------- 账户 ---------------- */
    case 'register': {
        $username = trim((string)($in['username'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $inviteCode = strtoupper(trim((string)($in['invite_code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $username)) {
            kl_fail('用户名需为 2-20 位中英文、数字或下划线');
        }
        if (strlen($password) < 6) {
            kl_fail('密码至少 6 位');
        }
        if (kl_one('SELECT id FROM {users} WHERE username = ?', [$username])) {
            kl_fail('该用户名已被注册');
        }
        $now = kl_now();
        $userId = kl_insert('users', [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'points' => 0,
            'level' => 1,
            'invited_count' => 0,
            'invite_limit' => 30,
            'invite_code' => kl_random_code(8),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        kl_insert('model_configs', [
            'user_id' => $userId,
            'provider' => '自定义 OpenAI 兼容',
            'api_base' => '',
            'api_key' => '',
            'model' => '',
            'temperature' => 0.7,
            'max_tokens' => 129000,
            'system_prompt' => '你是一位资深网络小说编辑与作者，擅长结构化输出。',
            'updated_at' => $now,
        ]);
        $inviteMsg = '';
        if ($inviteCode !== '') {
            $inviter = kl_one('SELECT * FROM {users} WHERE invite_code = ?', [$inviteCode]);
            if ($inviter) {
                if ((int)$inviter['invited_count'] < (int)$inviter['invite_limit']) {
                    kl_update('users', [
                        'points' => (int)$inviter['points'] + 300,
                        'invited_count' => (int)$inviter['invited_count'] + 1,
                        'updated_at' => $now,
                    ], 'id = ?', [$inviter['id']]);
                    $inviteMsg = '邀请人已获得 300 积分奖励';
                } else {
                    $inviteMsg = '邀请人已达邀请上限，本次不再发放奖励';
                }
            } else {
                $inviteMsg = '邀请码不存在，已忽略';
            }
        }
        $_SESSION['user_id'] = $userId;
        kl_insert('login_logs', ['user_id' => $userId, 'ip' => kl_client_ip(), 'device' => kl_device(), 'created_at' => $now]);
        kl_ok(['message' => '注册成功' . ($inviteMsg ? '，' . $inviteMsg : '')]);
    }
    // ... (rest of file unchanged) 
    case 'model_get': {
        $cfg = kl_model_config(kl_user_id());
        $cfg['provider'] = kl_normalize_provider((string)$cfg['provider']);
        $cfg['api_key'] = kl_mask_key((string)$cfg['api_key']);
        $cfg['has_key'] = $cfg['api_key'] !== '';
        kl_ok(['config' => $cfg]);
    }
    case 'model_save': {
        $userId = kl_user_id();
        $current = kl_model_config($userId);
        $apiBase = trim((string)($in['api_base'] ?? ''));
        if ($apiBase !== '' && !filter_var($apiBase, FILTER_VALIDATE_URL)) {
            kl_fail('API 地址需为合法的 http/https 地址');
        }
        $temperature = (float)($in['temperature'] ?? 0.7);
        if ($temperature < 0 || $temperature > 2) {
            kl_fail('温度参数范围为 0 到 2');
        }
        $rawKey = (string)($in['api_key'] ?? '');
        // provider 校验
        $allowedProviders = ['zhipu','official','custom'];
        $providerRaw = trim((string)($in['provider'] ?? 'custom'));
        $providerNorm = kl_normalize_provider($providerRaw);
        if (!in_array($providerNorm, $allowedProviders, true)) {
            kl_fail('不支持的接口类型');
        }
        $apiKey = (str_contains($rawKey, '*') || $rawKey === '')
            ? (string)$current['api_key']
            : kl_encrypt(trim($rawKey));
        kl_update('model_configs', [
            'provider' => kl_normalize_provider(mb_substr(trim((string)($in['provider'] ?? 'custom')), 0, 40) ?: 'custom'),
            'api_base' => $apiBase,
            'api_key' => $apiKey,
            'model' => mb_substr(trim((string)($in['model'] ?? '')), 0, 80),
            'temperature' => $temperature,
            'max_tokens' => max(0, (int)($in['max_tokens'] ?? 129000)),
            'system_prompt' => (string)($in['system_prompt'] ?? ''),
            'updated_at' => kl_now(),
        ], 'user_id = ?', [$userId]);
        kl_ok(['message' => '模型配置已保存，所有 AI 调用将使用该配置']);
    }
    // ... remaining unchanged ...
}
?>