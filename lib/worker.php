<?php
/**
 * 后台任务执行器：拆书导入
 * 既可通过 CLI 调用（php lib/worker.php <task_id>），也可被 api.php include 后调用 kl_run_task()。
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('kl_run_task')) {
    /** 执行单个后台任务，内部捕获所有异常并写回任务状态 */
    function kl_run_task(int $taskId): array
    {
        $task = kl_one('SELECT * FROM {tasks} WHERE id = ?', [$taskId]);
        if (!$task) {
            return ['ok' => false, 'message' => '任务不存在'];
        }
        if ($task['status'] === 'running' || $task['status'] === 'done') {
            return ['ok' => false, 'message' => '任务已在执行或已完成'];
        }

        kl_update('tasks', ['status' => 'running', 'error' => ''], 'id = ?', [$taskId]);
        try {
            $result = kl_task_split_book($task);
            kl_update('tasks', [
                'status' => 'done',
                'result' => (string)json_encode($result, JSON_UNESCAPED_UNICODE),
                'book_id' => (int)$result['book_id'],
                'finished_at' => kl_now(),
            ], 'id = ?', [$taskId]);
            return ['ok' => true] + $result;
        } catch (Throwable $e) {
            kl_update('tasks', [
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => kl_now(),
            ], 'id = ?', [$taskId]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** 拆书流程：读取 TXT → 章节切分 → 按范围截取 → AI 生成大纲与世界观 → 写入作品表 */
    function kl_task_split_book(array $task): array
    {
        $path = (string)$task['file_path'];
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('上传的 TXT 文件不存在或已被清理');
        }
        $raw = (string)file_get_contents($path);
        if (trim($raw) === '') {
            throw new RuntimeException('TXT 文件内容为空');
        }
        $content = kl_to_utf8($raw);

        $chapters = kl_split_chapters($content);
        if (!$chapters) {
            throw new RuntimeException('未能识别到任何章节内容，请检查文件格式');
        }
        $total = count($chapters);

        if ((string)$task['parse_scope'] === 'tail') {
            $count = max(1, (int)$task['chapter_count']);
            $picked = array_slice($chapters, -$count);
            $scopeText = sprintf('全书共识别 %d 章，本次解析末 %d 章', $total, count($picked));
        } else {
            $picked = $chapters;
            $scopeText = sprintf('全书共识别 %d 章，本次整本解析', $total);
        }

        $digest = kl_chapter_digest($picked);
        $userId = (int)$task['user_id'];
        $bookName = (string)$task['title'] !== '' ? (string)$task['title'] : '未命名作品';

        $prompt = "你是一位资深网文编辑，请根据下面的小说章节内容完成拆书分析。\n"
            . "作品文件名：{$bookName}\n{$scopeText}\n\n"
            . "章节内容摘录如下：\n{$digest}\n\n"
            . "严格输出 JSON，不要输出任何解释文字，格式：\n"
            . '{"title":"推断的书名","genre":"主要类型","intro":"200字内简介",'
            . '"outline":"按章节顺序的大纲，每章一行，格式为 章节名：核心事件",'
            . '"worldview":"世界观设定，包含时代背景、力量体系、势力分布、核心规则"}';

        $ai = kl_ai_chat($userId, $prompt, ['temperature' => 0.5, 'timeout' => 180]);
        if (!$ai['ok']) {
            throw new RuntimeException('AI 拆解失败：' . $ai['message']);
        }
        $data = kl_extract_json($ai['content']);
        if (!$data) {
            $data = [
                'title' => $bookName,
                'genre' => '未分类',
                'intro' => mb_substr(trim($ai['content']), 0, 300),
                'outline' => trim($ai['content']),
                'worldview' => '',
            ];
        }

        $words = mb_strlen(preg_replace('/\s+/u', '', $content) ?? '');
        $title = trim((string)($data['title'] ?? '')) !== '' ? mb_substr(trim((string)$data['title']), 0, 100) : $bookName;
        $now = kl_now();
        $bookId = kl_insert('books', [
            'user_id' => $userId,
            'title' => $title,
            'genre' => mb_substr(trim((string)($data['genre'] ?? '未分类')), 0, 50),
            'intro' => mb_substr(trim((string)($data['intro'] ?? '')), 0, 1000),
            'target_words' => max(100000, (int)(ceil($words / 100000) * 100000)),
            'words' => $words,
            'status' => 'writing',
            'outline' => (string)($data['outline'] ?? ''),
            'worldview' => (string)($data['worldview'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'book_id' => $bookId,
            'title' => $title,
            'chapters_total' => $total,
            'chapters_parsed' => count($picked),
            'words' => $words,
            'message' => sprintf('拆解完成：%s，解析 %d / %d 章', $title, count($picked), $total),
        ];
    }

    /** 统一转换为 UTF-8，兼容 GBK/GB18030 编码的 TXT */
    function kl_to_utf8(string $text): string
    {
        if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'GB18030');
        return is_string($converted) && $converted !== '' ? $converted : $text;
    }

    /** 压缩章节内容，避免超出模型上下文：每章取开头与结尾片段 */
    function kl_chapter_digest(array $chapters, int $limit = 24000): string
    {
        $count = count($chapters);
        $perChapter = $count > 0 ? max(300, (int)floor($limit / $count)) : $limit;
        $parts = [];
        foreach ($chapters as $chapter) {
            $body = preg_replace('/\n{2,}/', "\n", (string)$chapter['body']) ?? '';
            if (mb_strlen($body) > $perChapter) {
                $head = mb_substr($body, 0, (int)floor($perChapter * 0.7));
                $tail = mb_substr($body, -(int)floor($perChapter * 0.3));
                $body = $head . "\n……（中略）……\n" . $tail;
            }
            $parts[] = '【' . $chapter['title'] . "】\n" . $body;
        }
        return mb_substr(implode("\n\n", $parts), 0, $limit);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    $result = kl_run_task((int)$argv[1]);
    fwrite(STDOUT, (string)json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
}
