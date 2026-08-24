<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!kl_is_installed()) {
    kl_json(['ok' => false, 'message' => '系统尚未安装，请先访问 install/index.php 完成安装'], 503);
}

kl_start_session();
$action = (string)($_GET['action'] ?? '');
$in = kl_input();

$publicActions = ['login', 'register', 'session'];
if (!in_array($action, $publicActions, true)) {
    kl_require_login();
}

switch ($action) {
    /* ---------------- 账户 ---------------- */
    case 'register': {
        $username = trim((string)($in['username'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $inviteCode = strtoupper(trim((string)($in['invite_code'] ?? '')));
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

    case 'login': {
        $username = trim((string)($in['username'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $user = kl_one('SELECT * FROM {users} WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            kl_fail('用户名或密码错误', 401);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        kl_insert('login_logs', [
            'user_id' => (int)$user['id'],
            'ip' => kl_client_ip(),
            'device' => kl_device(),
            'created_at' => kl_now(),
        ]);
        kl_ok(['message' => '登录成功']);
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        kl_ok(['message' => '已退出登录']);
    }

    case 'session': {
        $user = kl_current_user();
        if (!$user) {
            kl_json(['ok' => false, 'message' => '未登录'], 401);
        }
        kl_ok(['user' => kl_profile((int)$user['id'])]);
    }

    case 'profile': {
        kl_ok(['user' => kl_profile(kl_user_id())]);
    }

    case 'checkin': {
        $userId = kl_user_id();
        $user = kl_current_user();
        $today = date('Y-m-d');
        if ((string)$user['last_checkin'] === $today) {
            kl_fail('今日已签到，明天再来领取积分');
        }
        $points = (int)$user['points'] + 100;
        $words = (int)kl_value('SELECT COALESCE(SUM(words),0) FROM {books} WHERE user_id = ?', [$userId], 0);
        kl_update('users', [
            'points' => $points,
            'last_checkin' => $today,
            'level' => kl_calc_level($points, $words),
            'updated_at' => kl_now(),
        ], 'id = ?', [$userId]);
        kl_ok(['message' => '签到成功，获得 100 积分', 'user' => kl_profile($userId)]);
    }

    case 'change_password': {
        $userId = kl_user_id();
        $user = kl_current_user();
        $old = (string)($in['old_password'] ?? '');
        $new = (string)($in['new_password'] ?? '');
        if (!password_verify($old, (string)$user['password_hash'])) {
            kl_fail('原密码不正确');
        }
        if (strlen($new) < 6) {
            kl_fail('新密码至少 6 位');
        }
        kl_update('users', [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'updated_at' => kl_now(),
        ], 'id = ?', [$userId]);
        $_SESSION = [];
        session_destroy();
        kl_ok(['message' => '密码修改成功，请重新登录', 'relogin' => true]);
    }

    case 'login_logs': {
        kl_ok(['logs' => kl_all('SELECT ip, device, created_at FROM {login_logs} WHERE user_id = ? ORDER BY id DESC LIMIT 10', [kl_user_id()])]);
    }

    case 'check_update': {
        $cfg = kl_config();
        $url = (string)($cfg['update_check_url'] ?? '');
        if ($url === '') {
            kl_ok(['message' => '未配置更新检查接口', 'version' => KL_VERSION, 'latest' => null]);
        }
        $result = kl_http_json($url, ['Accept: application/json'], null, 8);
        if (!$result['ok']) {
            kl_ok(['message' => '更新接口暂不可用，请稍后重试或前往官网查看', 'version' => KL_VERSION, 'latest' => null]);
        }
        $latest = (string)($result['data']['version'] ?? '');
        kl_ok([
            'version' => KL_VERSION,
            'latest' => $latest,
            'message' => $latest !== '' && version_compare($latest, KL_VERSION, '>')
                ? '发现新版本 ' . $latest
                : '当前已是最新版本',
        ]);
    }

    /* ---------------- 作品 / 书架 ---------------- */
    case 'books': {
        $userId = kl_user_id();
        $books = kl_all('SELECT * FROM {books} WHERE user_id = ? ORDER BY updated_at DESC, id DESC', [$userId]);
        $total = 0;
        $writing = 0;
        $finished = 0;
        foreach ($books as $b) {
            $total += (int)$b['words'];
            $b['status'] === 'finished' ? $finished++ : $writing++;
        }
        kl_ok([
            'books' => $books,
            'stats' => [
                'total' => count($books),
                'writing' => $writing,
                'finished' => $finished,
                'words' => $total,
                'words_text' => kl_format_words($total),
            ],
        ]);
    }

    case 'book_save': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            kl_fail('请填写书名');
        }
        $data = [
            'title' => mb_substr($title, 0, 60),
            'genre' => mb_substr(trim((string)($in['genre'] ?? '')), 0, 60),
            'intro' => (string)($in['intro'] ?? ''),
            'target_words' => max(1000, (int)($in['target_words'] ?? 1000000)),
            'words' => max(0, (int)($in['words'] ?? 0)),
            'status' => ($in['status'] ?? 'writing') === 'finished' ? 'finished' : 'writing',
            'outline' => (string)($in['outline'] ?? ''),
            'worldview' => (string)($in['worldview'] ?? ''),
            'updated_at' => kl_now(),
        ];
        if ($id > 0) {
            if (!kl_one('SELECT id FROM {books} WHERE id = ? AND user_id = ?', [$id, $userId])) {
                kl_fail('作品不存在或无权访问', 403);
            }
            kl_update('books', $data, 'id = ? AND user_id = ?', [$id, $userId]);
            kl_ok(['message' => '作品已更新', 'id' => $id]);
        }
        $data['user_id'] = $userId;
        $data['created_at'] = kl_now();
        $newId = kl_insert('books', $data);
        kl_ok(['message' => '作品创建成功', 'id' => $newId]);
    }

    case 'book_delete': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        if (!kl_one('SELECT id FROM {books} WHERE id = ? AND user_id = ?', [$id, $userId])) {
            kl_fail('作品不存在或无权访问', 403);
        }
        kl_delete('tasks', 'book_id = ? AND user_id = ?', [$id, $userId]);
        kl_delete('books', 'id = ? AND user_id = ?', [$id, $userId]);
        kl_ok(['message' => '作品及关联数据已删除']);
    }

    /* ---------------- 写作风格库 ---------------- */
    case 'styles': {
        $userId = kl_user_id();
        kl_ok(['styles' => kl_all(
            'SELECT * FROM {styles} WHERE user_id = 0 OR user_id = ? ORDER BY is_preset DESC, id ASC',
            [$userId]
        )]);
    }

    case 'style_save': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            kl_fail('请填写风格名称');
        }
        $data = [
            'name' => mb_substr($name, 0, 30),
            'description' => (string)($in['description'] ?? ''),
            'genres' => mb_substr(trim((string)($in['genres'] ?? '')), 0, 100),
        ];
        if ($id > 0) {
            if (!kl_one('SELECT id FROM {styles} WHERE id = ? AND user_id = ?', [$id, $userId])) {
                kl_fail('系统预设风格不可修改，或该风格不属于当前用户', 403);
            }
            kl_update('styles', $data, 'id = ? AND user_id = ?', [$id, $userId]);
            kl_ok(['message' => '风格已更新']);
        }
        $data['user_id'] = $userId;
        $data['is_preset'] = 0;
        $data['created_at'] = kl_now();
        kl_insert('styles', $data);
        kl_ok(['message' => '风格已创建']);
    }

    case 'style_delete': {
        $userId = kl_user_id();
        if (!kl_delete('styles', 'id = ? AND user_id = ? AND is_preset = 0', [(int)($in['id'] ?? 0), $userId])) {
            kl_fail('仅可删除自己创建的自定义风格', 403);
        }
        kl_ok(['message' => '风格已删除']);
    }

    /* ---------------- 提示词模板 ---------------- */
    case 'prompts': {
        $userId = kl_user_id();
        $keyword = trim((string)($_GET['q'] ?? ''));
        if ($keyword !== '') {
            kl_ok(['prompts' => kl_all(
                'SELECT * FROM {prompts} WHERE (user_id = 0 OR user_id = ?) AND name LIKE ? ORDER BY user_id ASC, id ASC',
                [$userId, '%' . $keyword . '%']
            )]);
        }
        kl_ok(['prompts' => kl_all(
            'SELECT * FROM {prompts} WHERE user_id = 0 OR user_id = ? ORDER BY user_id ASC, id ASC',
            [$userId]
        )]);
    }

    case 'prompt_save': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $content = (string)($in['content'] ?? '');
        if ($name === '' || trim($content) === '') {
            kl_fail('请填写模板名称与模板内容');
        }
        preg_match_all('/\{(\w+)\}/', $content, $m);
        $data = [
            'name' => mb_substr($name, 0, 60),
            'content' => $content,
            'type' => mb_substr(trim((string)($in['type'] ?? 'general')), 0, 20) ?: 'general',
            'variables' => implode(',', array_map(static fn($v) => '{' . $v . '}', array_unique($m[1] ?? []))),
        ];
        if ($id > 0) {
            if (!kl_one('SELECT id FROM {prompts} WHERE id = ? AND user_id = ?', [$id, $userId])) {
                kl_fail('系统预设模板不可修改，或该模板不属于当前用户', 403);
            }
            kl_update('prompts', $data, 'id = ? AND user_id = ?', [$id, $userId]);
            kl_ok(['message' => '模板已更新']);
        }
        $data['user_id'] = $userId;
        $data['created_at'] = kl_now();
        kl_insert('prompts', $data);
        kl_ok(['message' => '模板已创建']);
    }

    case 'prompt_delete': {
        if (!kl_delete('prompts', 'id = ? AND user_id = ?', [(int)($in['id'] ?? 0), kl_user_id()])) {
            kl_fail('仅可删除自己创建的模板', 403);
        }
        kl_ok(['message' => '模板已删除']);
    }

    /* ---------------- 技能 ---------------- */
    case 'skills': {
        $skills = kl_all('SELECT * FROM {skills} ORDER BY id ASC');
        foreach ($skills as &$skill) {
            $file = KL_ROOT . '/' . $skill['config_path'];
            $skill['config_exists'] = is_file($file);
        }
        kl_ok(['skills' => $skills]);
    }

    case 'skill_toggle': {
        $id = (int)($in['id'] ?? 0);
        $skill = kl_one('SELECT * FROM {skills} WHERE id = ?', [$id]);
        if (!$skill) {
            kl_fail('技能不存在');
        }
        $enabled = (int)$skill['enabled'] === 1 ? 0 : 1;
        kl_update('skills', ['enabled' => $enabled], 'id = ?', [$id]);
        kl_ok(['message' => $enabled ? '技能已启用' : '技能已停用', 'enabled' => $enabled]);
    }

    /* ---------------- MCP 插件 ---------------- */
    case 'mcp': {
        $userId = kl_user_id();
        $plugins = kl_all('SELECT * FROM {mcp_plugins} WHERE user_id = ? ORDER BY id ASC', [$userId]);
        $stats = kl_one(
            'SELECT COALESCE(SUM(s.total_calls),0) AS total, COALESCE(SUM(s.success_calls),0) AS success,
                    COALESCE(SUM(s.fail_calls),0) AS fail, COALESCE(SUM(s.total_ms),0) AS ms
             FROM {mcp_stats} s INNER JOIN {mcp_plugins} p ON p.id = s.plugin_id WHERE p.user_id = ?',
            [$userId]
        ) ?? ['total' => 0, 'success' => 0, 'fail' => 0, 'ms' => 0];
        $total = (int)$stats['total'];
        kl_ok([
            'plugins' => $plugins,
            'stats' => [
                'total' => $total,
                'success' => (int)$stats['success'],
                'fail' => (int)$stats['fail'],
                'rate' => $total > 0 ? round((int)$stats['success'] / $total * 100, 1) : 0.0,
                'avg_ms' => $total > 0 ? (int)round((int)$stats['ms'] / $total) : 0,
            ],
        ]);
    }

    case 'mcp_save': {
        $userId = kl_user_id();
        // 自动插入默认插件（首次打开且未提交任何插件信息时）
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $url  = trim((string)($in['url'] ?? ''));
        $type = in_array($in['type'] ?? '', ['http', 'streamable_http', 'sse'], true) ? $in['type'] : 'http';

        // 当请求体为空且是新建操作时，创建两条默认插件
        if ($id === 0 && $name === '' && $url === '' && $type === 'http') {
            $defaultPlugins = [
                [
                    'name' => '智谱清言',
                    'url'  => 'https://open.bigmodel.cn/api/paas/v4',
                    'type' => 'http',
                ],
                [
                    'name' => '官方接口',
                    'url'  => 'https://ai.anyyds.cn/v1',
                    'type' => 'http',
                ],
                [
                    'name' => 'Openapi兼容接口',
                    'url'  => '',   // 需要用户自行填写后保存
                    'type' => 'http',
                ],
            ];
            foreach ($defaultPlugins as $def) {
                $def['user_id']    = $userId;
                $def['created_at'] = kl_now();
                $pid = kl_insert('mcp_plugins', $def);
                kl_insert('mcp_stats', ['plugin_id' => $pid, 'created_at' => kl_now()]);
            }
            kl_ok(['message' => '已自动创建默认插件（智谱清言 & 内置插件）']);
            break;
        }
        if ($name === '') {
            kl_fail('请填写插件名称');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            kl_fail('插件地址需为合法的 http/https 地址');
        }
        $data = ['name' => mb_substr($name, 0, 40), 'url' => $url, 'type' => $type];
        if ($id > 0) {
            if (!kl_one('SELECT id FROM {mcp_plugins} WHERE id = ? AND user_id = ?', [$id, $userId])) {
                kl_fail('插件不存在或无权访问', 403);
            }
            kl_update('mcp_plugins', $data, 'id = ? AND user_id = ?', [$id, $userId]);
            kl_ok(['message' => '插件已更新']);
        }
        $data['user_id'] = $userId;
        $data['created_at'] = kl_now();
        $pluginId = kl_insert('mcp_plugins', $data);
        kl_insert('mcp_stats', ['plugin_id' => $pluginId, 'created_at' => kl_now()]);
        kl_ok(['message' => '插件已添加']);
    }

    case 'mcp_delete': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        if (!kl_one('SELECT id FROM {mcp_plugins} WHERE id = ? AND user_id = ?', [$id, $userId])) {
            kl_fail('插件不存在或无权访问', 403);
        }
        kl_delete('mcp_stats', 'plugin_id = ?', [$id]);
        kl_delete('mcp_plugins', 'id = ? AND user_id = ?', [$id, $userId]);
        kl_ok(['message' => '插件已删除']);
    }

    case 'mcp_call': {
        $userId = kl_user_id();
        $id = (int)($in['id'] ?? 0);
        $plugin = kl_one('SELECT * FROM {mcp_plugins} WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$plugin) {
            kl_fail('插件不存在或无权访问', 403);
        }
        $start = microtime(true);
        $result = kl_http_json(
            (string)$plugin['url'],
            ['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            10
        );
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $stat = kl_one('SELECT * FROM {mcp_stats} WHERE plugin_id = ?', [$id]);
        if (!$stat) {
            kl_insert('mcp_stats', ['plugin_id' => $id, 'created_at' => kl_now()]);
            $stat = kl_one('SELECT * FROM {mcp_stats} WHERE plugin_id = ?', [$id]);
        }
        kl_update('mcp_stats', [
            'total_calls' => (int)$stat['total_calls'] + 1,
            'success_calls' => (int)$stat['success_calls'] + ($result['ok'] ? 1 : 0),
            'fail_calls' => (int)$stat['fail_calls'] + ($result['ok'] ? 0 : 1),
            'total_ms' => (int)$stat['total_ms'] + $elapsed,
        ], 'plugin_id = ?', [$id]);
        kl_update('mcp_plugins', ['last_called_at' => kl_now()], 'id = ?', [$id]);
        kl_ok([
            'message' => $result['ok']
                ? '调用成功，耗时 ' . $elapsed . ' ms'
                : '调用失败（' . $result['message'] . '），耗时 ' . $elapsed . ' ms',
            'success' => $result['ok'],
            'elapsed' => $elapsed,
        ]);
    }

    /* ---------------- 模型配置 ---------------- */
    case 'model_get': {
        $cfg = kl_model_config(kl_user_id());
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
        $apiKey = (str_contains($rawKey, '*') || $rawKey === '')
            ? (string)$current['api_key']
            : kl_encrypt(trim($rawKey));
        kl_update('model_configs', [
            'provider' => mb_substr(trim((string)($in['provider'] ?? '自定义 OpenAI 兼容')), 0, 40) ?: '自定义 OpenAI 兼容',
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

    case 'model_list': {
        $result = kl_ai_models(kl_user_id());
        $result['ok'] ? kl_ok(['models' => $result['models']]) : kl_fail($result['message']);
    }

    case 'model_info': {
        // 公共模型信息接口（无需登录）
        $cfg  = kl_config();
        $base = $cfg['model']['api_base'] ?? '';
        $list = [];
        // 1. 尝试远程获取模型列表
        if ($base) {
            $url  = rtrim($base, '/') . '/models';
            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (isset($data['data'])) {
                    $list = $data['data'];
                } elseif (isset($data['models'])) {
                    $list = $data['models'];
                }
            }
        }
        // 2. 若未获取，则回退本地模型配置
        if (empty($list)) {
            $list = array_column(kl_all('SELECT * FROM {model_configs}'), 'model');
        }
        kl_ok(['models' => $list]);
        break;
    }
    // 动态切换数据库配置
    case 'db_switch': {
        // 期待的字段：driver, host, port, database, username, password, charset, prefix（可选）
        $cfg = [
            'driver'   => $in['driver'] ?? 'sqlite',
            'host'     => $in['host'] ?? '',
            'port'     => $in['port'] ?? 0,
            'database' => $in['database'] ?? '',
            'username' => $in['username'] ?? '',
            'password' => $in['password'] ?? '',
            'charset'  => $in['charset'] ?? 'utf8mb4',
            'prefix'   => $in['prefix'] ?? 'kl_',
        ];
        if (!in_array($cfg['driver'], ['sqlite', 'mysql'])) {
            kl_fail('不支持的数据库驱动');
        }
        // 设置运行时数据库配置
        kl_set_runtime_db($cfg);
        kl_ok(['message' => '已切换数据库配置']);
        break;
    }
    /* ---------------- AI 场景 ---------------- */
    case 'ai_inspiration': {
        $userId = kl_user_id();
        $genres = array_filter(array_map('trim', (array)($in['genres'] ?? [])));
        if (!$genres) {
            kl_fail('请先选择至少一种小说类型，才能生成书名');
        }
        $prompt = sprintf(
            "请为一部网络小说生成创作灵感。融合类型：%s。%s%s\n" .
            "严格输出 JSON，不要额外说明，格式：{\"title\":\"书名\",\"intro\":\"200字内简介\",\"theme\":\"核心主题\",\"outline\":\"分为开端/发展/高潮/结局四段的大纲\"}",
            implode('、', $genres),
            trim((string)($in['title'] ?? '')) !== '' ? '已有书名参考：' . $in['title'] . '。' : '',
            trim((string)($in['theme'] ?? '')) !== '' ? '主题要求：' . $in['theme'] . '。' : ''
        );
        if (trim((string)($in['intro'] ?? '')) !== '') {
            $prompt .= "\n已有简介参考：" . $in['intro'];
        }
        $result = kl_ai_chat($userId, $prompt, ['temperature' => 0.9]);
        if (!$result['ok']) {
            kl_fail($result['message']);
        }
        $json = kl_extract_json($result['content']);
        kl_ok([
            'data' => $json ?? ['title' => '', 'intro' => $result['content'], 'theme' => '', 'outline' => ''],
            'raw' => $result['content'],
        ]);
    }

    case 'ai_continue': {
        $userId = kl_user_id();
        $book = kl_one('SELECT * FROM {books} WHERE id = ? AND user_id = ?', [(int)($in['book_id'] ?? 0), $userId]);
        if (!$book) {
            kl_fail('作品不存在或无权访问', 403);
        }
        $style = null;
        if ((int)($in['style_id'] ?? 0) > 0) {
            $style = kl_one('SELECT * FROM {styles} WHERE id = ? AND (user_id = 0 OR user_id = ?)', [(int)$in['style_id'], $userId]);
        }
        $skill = null;
        if (trim((string)($in['skill_trigger'] ?? '')) !== '') {
            $skill = kl_find_skill_by_trigger(trim((string)$in['skill_trigger']));
        }
        $prompt = "请为小说《{$book['title']}》续写正文。\n"
            . "类型：{$book['genre']}\n简介：{$book['intro']}\n"
            . "大纲：" . mb_substr((string)$book['outline'], 0, 1500) . "\n"
            . "世界观：" . mb_substr((string)$book['worldview'], 0, 1500) . "\n"
            . '续写需求：' . trim((string)($in['requirement'] ?? '继续推进主线'));
        if ($style) {
            $prompt .= "\n写作风格【{$style['name']}】：{$style['description']}";
        }
        if ($skill) {
            $prompt .= "\n应用技能【{$skill['name']}】：{$skill['description']}";
            if (!empty($skill['instruction'])) {
                $prompt .= "\n技能执行要求：" . $skill['instruction'];
            }
        }
        $prompt .= "\n请直接输出正文，不要输出解释或标题。";
        $result = kl_ai_chat($userId, $prompt);
        if (!$result['ok']) {
            kl_fail($result['message']);
        }
        kl_ok(['content' => $result['content'], 'skill' => $skill['name'] ?? null]);
    }

    case 'ai_polish': {
        $userId = kl_user_id();
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            kl_fail('请填写待润色的文本');
        }
        $style = kl_one('SELECT * FROM {styles} WHERE id = ? AND (user_id = 0 OR user_id = ?)', [(int)($in['style_id'] ?? 0), $userId]);
        if (!$style) {
            kl_fail('请选择一个有效的写作风格');
        }
        $result = kl_ai_chat($userId, sprintf(
            "请按【%s】风格改写以下文本。风格说明：%s；适用题材：%s。\n保持原意与人物设定，只输出改写后的正文。\n\n%s",
            $style['name'],
            $style['description'],
            $style['genres'],
            $text
        ));
        $result['ok'] ? kl_ok(['content' => $result['content'], 'style' => $style['name']]) : kl_fail($result['message']);
    }

    case 'ai_skill': {
        $userId = kl_user_id();
        $skill = kl_find_skill_by_trigger(trim((string)($in['trigger'] ?? '')));
        if (!$skill) {
            kl_fail('未匹配到技能，请检查触发词，或先在技能管理中启用该技能');
        }
        $text = trim((string)($in['text'] ?? ''));
        $prompt = "技能【{$skill['name']}】（{$skill['type']}）：{$skill['description']}\n";
        if (!empty($skill['instruction'])) {
            $prompt .= "执行要求：{$skill['instruction']}\n";
        }
        if (!empty($skill['output'])) {
            $prompt .= "输出结构：{$skill['output']}\n";
        }
        $prompt .= $text !== '' ? "\n待处理内容：\n" . $text : "\n无附加内容，请直接按技能职责给出结果。";
        $result = kl_ai_chat($userId, $prompt);
        $result['ok'] ? kl_ok(['content' => $result['content'], 'skill' => $skill['name']]) : kl_fail($result['message']);
    }

    case 'ai_prompt_run': {
        $userId = kl_user_id();
        $prompt = kl_one('SELECT * FROM {prompts} WHERE id = ? AND (user_id = 0 OR user_id = ?)', [(int)($in['id'] ?? 0), $userId]);
        if (!$prompt) {
            kl_fail('模板不存在或无权访问', 403);
        }
        $content = (string)$prompt['content'];
        $book = null;
        if ((int)($in['book_id'] ?? 0) > 0) {
            $book = kl_one('SELECT * FROM {books} WHERE id = ? AND user_id = ?', [(int)$in['book_id'], $userId]);
        }
        $vars = (array)($in['vars'] ?? []);
        $map = [
            '{title}' => $vars['title'] ?? ($book['title'] ?? ''),
            '{genre}' => $vars['genre'] ?? ($book['genre'] ?? ''),
            '{theme}' => $vars['theme'] ?? '',
            '{description}' => $vars['description'] ?? ($book['intro'] ?? ''),
            '{time_period}' => $vars['time_period'] ?? '',
            '{location}' => $vars['location'] ?? '',
        ];
        $content = str_replace(array_keys($map), array_values($map), $content);
        $result = kl_ai_chat($userId, $content);
        $result['ok'] ? kl_ok(['content' => $result['content'], 'prompt' => $content]) : kl_fail($result['message']);
    }

    /* ---------------- 拆书导入 ---------------- */
    case 'tasks': {
        kl_ok(['tasks' => kl_all(
            'SELECT id, type, status, title, parse_scope, chapter_count, error, book_id, created_at, finished_at
             FROM {tasks} WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [kl_user_id()]
        )]);
    }

    case 'task_create': {
        $userId = kl_user_id();
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            kl_fail('请选择要上传的 TXT 文件');
        }
        $file = $_FILES['file'];
        if ((int)$file['size'] > 50 * 1024 * 1024) {
            kl_fail('文件大小超过 50MB 上限');
        }
        if (strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'txt') {
            kl_fail('仅允许上传 TXT 格式文件');
        }
        if (extension_loaded('fileinfo')) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            if ($mime !== false && !str_starts_with((string)$mime, 'text/')) {
                kl_fail('文件内容不是纯文本，请检查后重新上传');
            }
        }
        if (!is_dir(KL_UPLOAD_DIR) && !@mkdir(KL_UPLOAD_DIR, 0777, true)) {
            kl_fail('uploads 目录不可写', 500);
        }
        $stored = KL_UPLOAD_DIR . '/' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.txt';
        if (!move_uploaded_file((string)$file['tmp_name'], $stored)) {
            kl_fail('文件保存失败，请检查 uploads 目录权限', 500);
        }
        $scope = ($_POST['parse_scope'] ?? 'tail') === 'all' ? 'all' : 'tail';
        $count = (int)($_POST['chapter_count'] ?? 20);
        if (!in_array($count, [10, 15, 20, 25, 30, 40], true)) {
            $count = 20;
        }
        $taskId = kl_insert('tasks', [
            'user_id' => $userId,
            'type' => 'split_book',
            'status' => 'queued',
            'title' => mb_substr(pathinfo((string)$file['name'], PATHINFO_FILENAME), 0, 100),
            'file_path' => $stored,
            'parse_scope' => $scope,
            'chapter_count' => $count,
            'created_at' => kl_now(),
        ]);
        kl_dispatch_worker($taskId);
        kl_ok(['message' => '任务已提交，正在后台执行，可离开页面稍后查看', 'task_id' => $taskId]);
    }

    case 'task_run': {
        require_once KL_ROOT . '/lib/worker.php';
        $userId = kl_user_id();
        $taskId = (int)($in['id'] ?? 0);
        if (!kl_one('SELECT id FROM {tasks} WHERE id = ? AND user_id = ?', [$taskId, $userId])) {
            kl_fail('任务不存在或无权访问', 403);
        }
        kl_run_task($taskId);
        kl_ok(['message' => '任务已执行完毕，请查看任务状态']);
    }

    default:
        kl_fail('未知的接口操作：' . $action, 404);
}

/* ---------------- 内部函数 ---------------- */
function kl_profile(int $userId): array
{
    $user = kl_one('SELECT * FROM {users} WHERE id = ?', [$userId]);
    $books = (int)kl_value('SELECT COUNT(*) FROM {books} WHERE user_id = ?', [$userId], 0);
    $words = (int)kl_value('SELECT COALESCE(SUM(words),0) FROM {books} WHERE user_id = ?', [$userId], 0);
    $level = kl_calc_level((int)$user['points'], $words);
    if ($level !== (int)$user['level']) {
        kl_update('users', ['level' => $level, 'updated_at' => kl_now()], 'id = ?', [$userId]);
    }
    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'points' => (int)$user['points'],
        'level' => $level,
        'invited_count' => (int)$user['invited_count'],
        'invite_limit' => (int)$user['invite_limit'],
        'invite_code' => $user['invite_code'],
        'checked_in' => (string)$user['last_checkin'] === date('Y-m-d'),
        'books' => $books,
        'words' => $words,
        'words_text' => kl_format_words($words),
        'version' => KL_VERSION,
        'created_at' => $user['created_at'],
    ];
}

function kl_find_skill_by_trigger(string $trigger): ?array
{
    if ($trigger === '') {
        return null;
    }
    foreach (kl_all('SELECT * FROM {skills} WHERE enabled = 1') as $skill) {
        foreach (explode(',', (string)$skill['triggers']) as $word) {
            $word = trim($word);
            if ($word !== '' && (mb_strtolower($word) === mb_strtolower($trigger) || mb_stripos($trigger, $word) !== false)) {
                $file = KL_ROOT . '/' . $skill['config_path'];
                if (is_file($file)) {
                    $config = json_decode((string)file_get_contents($file), true);
                    if (is_array($config)) {
                        $skill += ['instruction' => $config['instruction'] ?? '', 'output' => $config['output'] ?? ''];
                    }
                }
                return $skill;
            }
        }
    }
    return null;
}

/** 异步派发后台任务：优先 CLI 子进程，失败则在响应结束后同步执行 */
function kl_dispatch_worker(int $taskId): void
{
    $php = PHP_BINARY;
    $script = KL_ROOT . '/lib/worker.php';
    if (is_file($php) && !str_contains(strtolower(PHP_OS_FAMILY), 'windows')) {
        $cmd = sprintf('%s %s %d > /dev/null 2>&1 &', escapeshellcmd($php), escapeshellarg($script), $taskId);
        @exec($cmd, $out, $code);
        if ($code === 0) {
            return;
        }
    }
    register_shutdown_function(static function () use ($taskId, $script) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        require_once $script;
        kl_run_task($taskId);
    });
}
