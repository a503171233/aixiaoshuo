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

if (mb_strpos($last, 'worldview') !== false) {
    $content = "```json\n" . json_encode([
        'title' => '星辉纪元',
        'genre' => '玄幻',
        'intro' => '星辉纪元降临，少年自尘埃中崛起，逐步揭开天穹之上的真相。',
        'outline' => "第1章 星辉初现：少年觉醒星辉之力\n第2章 试炼开启：初入宗门试炼场\n第3章 暗流涌动：发现家族灭门真相",
        'worldview' => "时代背景：星辉纪元第三千年。\n力量体系：星辉境九阶，以星纹刻印为根基。\n势力分布：天穹学宫、九曜商会、荒原残族三足鼎立。\n核心规则：星辉不可逆流，每次越阶必付出记忆代价。",
    ], JSON_UNESCAPED_UNICODE) . "\n```";
} elseif (mb_strpos($last, '书名') !== false || mb_strpos($last, '灵感') !== false) {
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
