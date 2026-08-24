<?php
declare(strict_types=1);

function kl_model_config(int $userId): array
{
    $row = kl_one('SELECT * FROM {model_configs} WHERE user_id = ?', [$userId]);
    if (!$row) {
        $row = [
            'user_id' => $userId,
            'provider' => '自定义 OpenAI 兼容',
            'api_base' => '',
            'api_key' => '',
            'model' => '',
            'temperature' => 0.7,
            'max_tokens' => 129000,
            'system_prompt' => '你是一位资深网络小说编辑与作者，擅长结构化输出。',
            'updated_at' => kl_now(),
        ];
        kl_insert('model_configs', $row);
    }
    return $row;
}

function kl_ai_endpoint(string $base, string $path): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '') {
        return '';
    }
    if (str_ends_with($base, '/chat/completions')) {
        return $path === '/chat/completions' ? $base : preg_replace('#/chat/completions$#', '', $base) . $path;
    }
    return $base . $path;
}

function kl_http_json(string $url, array $headers, ?array $body, int $timeout): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false) {
        $message = match ($errno) {
            CURLE_OPERATION_TIMEDOUT => '接口请求超时，请检查网络或调大超时时间',
            CURLE_COULDNT_RESOLVE_HOST => '无法解析接口域名，请检查 API 地址',
            CURLE_COULDNT_CONNECT => '无法连接接口服务器',
            default => '网络请求失败：' . $error,
        };
        return ['ok' => false, 'message' => $message, 'status' => 0];
    }

    $data = json_decode((string)$response, true);
    if ($status >= 400) {
        $detail = $data['error']['message'] ?? mb_substr((string)$response, 0, 200);
        $message = match (true) {
            $status === 401 => 'API 密钥无效或未授权（401）',
            $status === 402, $status === 429 => '接口额度不足或请求过于频繁（' . $status . '）：' . $detail,
            $status === 404 => '接口地址不存在（404），请检查 API 地址是否包含 /v1',
            default => '接口返回错误（' . $status . '）：' . $detail,
        };
        return ['ok' => false, 'message' => $message, 'status' => $status];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'message' => '接口返回内容不是合法 JSON', 'status' => $status];
    }
    return ['ok' => true, 'data' => $data, 'status' => $status];
}

/**
 * 统一 AI 调用入口，所有模块都走这里
 * $options: system / history / temperature / max_tokens / timeout / stream
 */
function kl_ai_chat(int $userId, string $prompt, array $options = []): array
{
    $cfg = kl_model_config($userId);
    $apiBase = (string)$cfg['api_base'];
    $apiKey = kl_decrypt((string)$cfg['api_key']);
    $model = (string)$cfg['model'];

    if ($apiBase === '' || $apiKey === '' || $model === '') {
        return ['ok' => false, 'message' => '尚未完成模型配置，请先在设置页填写 API 地址、密钥与模型名称', 'need_config' => true];
    }

    $messages = [];
    $system = trim((string)($options['system'] ?? '')) ?: trim((string)$cfg['system_prompt']);
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($options['history'] ?? [] as $item) {
        if (!empty($item['role']) && isset($item['content'])) {
            $messages[] = ['role' => (string)$item['role'], 'content' => (string)$item['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $body = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float)($options['temperature'] ?? $cfg['temperature']),
        'stream' => false,
    ];
    $maxTokens = (int)($options['max_tokens'] ?? $cfg['max_tokens']);
    if ($maxTokens > 0) {
        $body['max_tokens'] = $maxTokens;
    }

    $result = kl_http_json(
        kl_ai_endpoint($apiBase, '/chat/completions'),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        $body,
        (int)($options['timeout'] ?? 30)
    );
    if (!$result['ok']) {
        return $result;
    }
    $content = $result['data']['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') {
        return ['ok' => false, 'message' => '模型返回内容为空'];
    }
    return ['ok' => true, 'content' => $content, 'usage' => $result['data']['usage'] ?? null];
}

/** 流式输出（SSE）预留：逐块回调 */
function kl_ai_stream(int $userId, string $prompt, callable $onChunk, array $options = []): array
{
    $cfg = kl_model_config($userId);
    $apiKey = kl_decrypt((string)$cfg['api_key']);
    $url = kl_ai_endpoint((string)$cfg['api_base'], '/chat/completions');
    if ($url === '' || $apiKey === '' || $cfg['model'] === '') {
        return ['ok' => false, 'message' => '尚未完成模型配置'];
    }
    $messages = [];
    $system = trim((string)($options['system'] ?? '')) ?: trim((string)$cfg['system_prompt']);
    if ($system !== '') {
        $messages[] = ['role' => 'system', 'content' => $system];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Accept: text/event-stream'],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $cfg['model'],
            'messages' => $messages,
            'temperature' => (float)$cfg['temperature'],
            'stream' => true,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => (int)($options['timeout'] ?? 120),
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($onChunk) {
            foreach (preg_split('/\r\n|\n|\r/', (string)$chunk) ?: [] as $line) {
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }
                $payload = substr($line, 6);
                if ($payload === '[DONE]') {
                    continue;
                }
                $json = json_decode($payload, true);
                $delta = $json['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $onChunk((string)$delta);
                }
            }
            return strlen((string)$chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return $ok === false ? ['ok' => false, 'message' => '流式请求失败：' . $error] : ['ok' => true];
}

/** 从 /models 接口拉取可用模型列表 */
function kl_ai_models(int $userId): array
{
    $cfg = kl_model_config($userId);
    $apiKey = kl_decrypt((string)$cfg['api_key']);
    $url = kl_ai_endpoint((string)$cfg['api_base'], '/models');
    if ($url === '' || $apiKey === '') {
        return ['ok' => false, 'message' => '请先填写 API 地址与密钥'];
    }
    $result = kl_http_json($url, ['Authorization: Bearer ' . $apiKey], null, 20);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message'] . '（该服务可能不支持 /models，可手动输入模型名称）'];
    }
    $models = [];
    foreach ($result['data']['data'] ?? $result['data']['models'] ?? [] as $item) {
        $id = is_array($item) ? ($item['id'] ?? $item['name'] ?? '') : (string)$item;
        if ($id !== '') {
            $models[] = $id;
        }
    }
    sort($models);
    return ['ok' => true, 'models' => $models];
}

/** 从模型输出里稳健地提取 JSON 对象 */
function kl_extract_json(string $text): ?array
{
    $text = trim($text);
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $json = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($json) ? $json : null;
}

/** 章节切分：支持 第X章 / Chapter X / 第X回 等 */
function kl_split_chapters(string $content): array
{
    $content = preg_replace("/\r\n|\r/", "\n", $content);
    $pattern = '/^[ \t　]*((?:第\s*[0-9一二三四五六七八九十百千零两]+\s*[章回节卷篇])|(?:Chapter\s+\d+)|(?:CHAPTER\s+\d+))[ \t　]*(.*)$/mu';
    if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        $blocks = preg_split('/\n{2,}/', trim($content)) ?: [];
        $chapters = [];
        foreach ($blocks as $i => $block) {
            if (trim($block) === '') {
                continue;
            }
            $chapters[] = ['title' => '片段 ' . ($i + 1), 'body' => trim($block)];
        }
        return $chapters;
    }
    $chapters = [];
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
        $offset = $matches[0][$i][1];
        $titleLine = trim($matches[0][$i][0]);
        $bodyStart = $offset + strlen($matches[0][$i][0]);
        $bodyEnd = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($content);
        $chapters[] = [
            'title' => $titleLine,
            'body' => trim(substr($content, $bodyStart, $bodyEnd - $bodyStart)),
        ];
    }
    return $chapters;
}
