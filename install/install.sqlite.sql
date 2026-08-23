-- KIrva 小说助手 SQLite 演示模式建表脚本
-- 与 install.sql 结构一致，用于无 MySQL 环境下的本地演示

CREATE TABLE IF NOT EXISTS `kl_users` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `username` TEXT NOT NULL UNIQUE,
  `password_hash` TEXT NOT NULL,
  `points` INTEGER NOT NULL DEFAULT 0,
  `level` INTEGER NOT NULL DEFAULT 1,
  `invited_count` INTEGER NOT NULL DEFAULT 0,
  `invite_limit` INTEGER NOT NULL DEFAULT 30,
  `invite_code` TEXT NOT NULL DEFAULT '',
  `last_checkin` TEXT DEFAULT NULL,
  `created_at` TEXT NOT NULL,
  `updated_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_login_logs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL,
  `ip` TEXT NOT NULL DEFAULT '',
  `device` TEXT NOT NULL DEFAULT '',
  `created_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_books` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL,
  `title` TEXT NOT NULL,
  `genre` TEXT NOT NULL DEFAULT '',
  `intro` TEXT,
  `target_words` INTEGER NOT NULL DEFAULT 1000000,
  `words` INTEGER NOT NULL DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT 'writing',
  `outline` TEXT,
  `worldview` TEXT,
  `created_at` TEXT NOT NULL,
  `updated_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_tasks` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL DEFAULT 'split_book',
  `status` TEXT NOT NULL DEFAULT 'queued',
  `title` TEXT NOT NULL DEFAULT '',
  `file_path` TEXT NOT NULL DEFAULT '',
  `parse_scope` TEXT NOT NULL DEFAULT 'tail',
  `chapter_count` INTEGER NOT NULL DEFAULT 20,
  `result` TEXT,
  `error` TEXT NOT NULL DEFAULT '',
  `book_id` INTEGER NOT NULL DEFAULT 0,
  `created_at` TEXT NOT NULL,
  `finished_at` TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS `kl_styles` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL DEFAULT 0,
  `name` TEXT NOT NULL,
  `description` TEXT,
  `genres` TEXT NOT NULL DEFAULT '',
  `is_preset` INTEGER NOT NULL DEFAULT 0,
  `created_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_prompts` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL DEFAULT 0,
  `name` TEXT NOT NULL,
  `content` TEXT,
  `type` TEXT NOT NULL DEFAULT 'general',
  `variables` TEXT NOT NULL DEFAULT '',
  `created_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_skills` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` TEXT NOT NULL,
  `type` TEXT NOT NULL DEFAULT '通用',
  `description` TEXT,
  `triggers` TEXT NOT NULL DEFAULT '',
  `config_path` TEXT NOT NULL DEFAULT '',
  `enabled` INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS `kl_mcp_plugins` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `url` TEXT NOT NULL DEFAULT '',
  `type` TEXT NOT NULL DEFAULT 'http',
  `created_at` TEXT NOT NULL,
  `last_called_at` TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS `kl_mcp_stats` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `plugin_id` INTEGER NOT NULL,
  `total_calls` INTEGER NOT NULL DEFAULT 0,
  `success_calls` INTEGER NOT NULL DEFAULT 0,
  `fail_calls` INTEGER NOT NULL DEFAULT 0,
  `total_ms` INTEGER NOT NULL DEFAULT 0,
  `created_at` TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS `kl_model_configs` (
  `user_id` INTEGER PRIMARY KEY,
  `provider` TEXT NOT NULL DEFAULT '自定义 OpenAI 兼容',
  `api_base` TEXT NOT NULL DEFAULT '',
  `api_key` TEXT NOT NULL DEFAULT '',
  `model` TEXT NOT NULL DEFAULT '',
  `temperature` REAL NOT NULL DEFAULT 0.7,
  `max_tokens` INTEGER NOT NULL DEFAULT 129000,
  `system_prompt` TEXT,
  `updated_at` TEXT NOT NULL
);

-- SEED DATA --
INSERT INTO `kl_styles` (`user_id`,`name`,`description`,`genres`,`is_preset`,`created_at`) VALUES
(0,'自然流畅','语言平实自然，节奏舒缓，读感顺滑，避免堆砌辞藻。','现代都市,现实题材',1,datetime('now','localtime')),
(0,'古典优雅','文白相间，讲究意象与韵律，多用四字句与典雅措辞。','古装,仙侠',1,datetime('now','localtime')),
(0,'现代简约','短句为主，信息密度高，节奏快，适配移动端阅读。','轻小说,网文',1,datetime('now','localtime')),
(0,'文艺细腻','注重心理描写与氛围营造，善用通感与细节。','纯文学,言情',1,datetime('now','localtime')),
(0,'紧张悬疑','悬念前置，信息克制，善用倒计时与不可靠叙述。','推理,惊悚',1,datetime('now','localtime')),
(0,'幽默诙谐','吐槽与反差制造笑点，对白轻快。','轻松,搞笑',1,datetime('now','localtime')),
(0,'爽文','节奏明快，主角逆袭打脸，冲突密集，章末留钩子。','玄幻,都市',0,datetime('now','localtime'));

INSERT INTO `kl_prompts` (`user_id`,`name`,`content`,`type`,`variables`,`created_at`) VALUES
(0,'MCP 角色规划','为小说《{title}》设计核心角色。题材：{genre}；主题：{theme}；时代背景：{time_period}；地理位置：{location}。请先使用可用工具检索参考资料，再输出 5 位角色的姓名、身份、欲望、缺陷与人物弧光。','mcp','{title},{genre},{theme},{time_period},{location}',datetime('now','localtime')),
(0,'MCP 世界观规划','为小说《{title}》构建世界观。题材：{genre}；主题：{theme}；简介：{description}。请输出地理格局、权力结构、力量体系、资源冲突与三条可延展的世界线索。','mcp','{title},{genre},{theme},{description}',datetime('now','localtime')),
(0,'MCP 工具测试','这是一个用于测试自定义提示词与工具调用的模板。请回显以下变量并说明你实际调用了哪些工具：{description}','mcp','{description}',datetime('now','localtime'));

INSERT INTO `kl_skills` (`name`,`type`,`description`,`triggers`,`config_path`,`enabled`) VALUES
('网文去 AI 味','润色','检测并清除文本中的 AI 写作痕迹：套话、排比堆砌、空泛形容与总结式收尾。','/story-deslop,去 AI 味,去味','skills/story-deslop.json',1),
('长篇网文拆文','长篇','深度拆解爆款长篇的黄金三章、人设架构、爽点设计与节奏控制。','/story-long-analyze,帮我拆这本书','skills/story-long-analyze.json',1),
('长篇网文扫榜','长篇','分析起点、番茄、晋江等平台排行，提炼当下热门题材与开局套路。','/story-long-scan,长篇什么火,起点排行','skills/story-long-scan.json',1),
('长篇网文写作','长篇','从大纲到正文，辅助长篇网络小说的连续创作与伏笔管理。','/story-long-write,写长篇,继续写','skills/story-long-write.json',1);

INSERT INTO `kl_mcp_plugins` (`user_id`,`name`,`url`,`type`,`created_at`) VALUES
(0,'MCP 测试插件示例','https://example.com/mcp','http',datetime('now','localtime'));
