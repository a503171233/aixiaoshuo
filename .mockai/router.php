<?php
header('Content-Type: application/json; charset=utf-8');
$uri = $_SERVER['REQUEST_URI'] ?? '';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

if (strpos($uri, '/models') !== false) {
    echo json_encode(['data' => [
        ['id' => 'mock-gpt-4o'],
        ['id' => 'mock-gpt-4o-mini'],
        ['id' => 'mock-deepseek-chat'],
    ]]);
    exit;
}

$last = '';
foreach (($body['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'user') {
        $last = (string)($m['content'] ?? '');
    }
}

if (mb_strpos($last, '书名') !== false || mb_strpos($last, '灵感') !== false) {
    $content = "```json\n" . json_encode([
        'title' => '星火燎原录',
        'intro' => '一个在赛博都市中觉醒古老修真血脉的少年，被迫在企业与仙门之间抉择。',
        'theme' => '科技与修真的碰撞',
        'outline' => "第一卷 觉醒\n第二卷 入局\n第三卷 燎原",
    ], JSON_UNESCAPED_UNICODE) . "\n```";
} else {
    $content = "【MOCK 回显】收到提示词，长度 " . mb_strlen($last) . " 字：\n" . mb_substr($last, 0, 400);
}

echo json_encode([
    'id' => 'chatcmpl-mock',
    'object' => 'chat.completion',
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => $content],
        'finish_reason' => 'stop',
    ]],
    'usage' => ['total_tokens' => 128],
], JSON_UNESCAPED_UNICODE);
