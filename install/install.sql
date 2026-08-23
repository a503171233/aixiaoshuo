-- KIrva 小说助手 数据库安装脚本 (MySQL 5.6+)
-- 默认表前缀 kl_ ，安装程序会替换为用户填写的前缀
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `kl_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `points` int(11) NOT NULL DEFAULT '0',
  `level` int(11) NOT NULL DEFAULT '1',
  `invited_count` int(11) NOT NULL DEFAULT '0',
  `invite_limit` int(11) NOT NULL DEFAULT '30',
  `invite_code` varchar(32) NOT NULL DEFAULT '',
  `last_checkin` date DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_invite_code` (`invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `device` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_books` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `genre` varchar(120) NOT NULL DEFAULT '',
  `intro` text,
  `target_words` int(11) NOT NULL DEFAULT '1000000',
  `words` int(11) NOT NULL DEFAULT '0',
  `status` varchar(20) NOT NULL DEFAULT 'writing',
  `outline` mediumtext,
  `worldview` mediumtext,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'split_book',
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `title` varchar(160) NOT NULL DEFAULT '',
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `parse_scope` varchar(20) NOT NULL DEFAULT 'tail',
  `chapter_count` int(11) NOT NULL DEFAULT '20',
  `result` mediumtext,
  `error` varchar(500) NOT NULL DEFAULT '',
  `book_id` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_styles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `name` varchar(60) NOT NULL,
  `description` text,
  `genres` varchar(200) NOT NULL DEFAULT '',
  `is_preset` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_prompts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `name` varchar(120) NOT NULL,
  `content` mediumtext,
  `type` varchar(40) NOT NULL DEFAULT 'general',
  `variables` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_skills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT '通用',
  `description` text,
  `triggers` varchar(255) NOT NULL DEFAULT '',
  `config_path` varchar(255) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_mcp_plugins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(30) NOT NULL DEFAULT 'http',
  `created_at` datetime NOT NULL,
  `last_called_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_mcp_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plugin_id` int(11) NOT NULL,
  `total_calls` int(11) NOT NULL DEFAULT '0',
  `success_calls` int(11) NOT NULL DEFAULT '0',
  `fail_calls` int(11) NOT NULL DEFAULT '0',
  `total_ms` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_plugin` (`plugin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kl_model_configs` (
  `user_id` int(11) NOT NULL,
  `provider` varchar(80) NOT NULL DEFAULT '自定义 OpenAI 兼容',
  `api_base` varchar(255) NOT NULL DEFAULT '',
  `api_key` varchar(500) NOT NULL DEFAULT '',
  `model` varchar(120) NOT NULL DEFAULT '',
  `temperature` decimal(3,2) NOT NULL DEFAULT '0.70',
  `max_tokens` int(11) NOT NULL DEFAULT '129000',
  `system_prompt` text,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEED DATA --
INSERT INTO `kl_styles` (`user_id`,`name`,`description`,`genres`,`is_preset`,`created_at`) VALUES
(0,'自然流畅','语言平实自然，节奏舒缓，读感顺滑，避免堆砌辞藻。','现代都市,现实题材',1,NOW()),
(0,'古典优雅','文白相间，讲究意象与韵律，多用四字句与典雅措辞。','古装,仙侠',1,NOW()),
(0,'现代简约','短句为主，信息密度高，节奏快，适配移动端阅读。','轻小说,网文',1,NOW()),
(0,'文艺细腻','注重心理描写与氛围营造，善用通感与细节。','纯文学,言情',1,NOW()),
(0,'紧张悬疑','悬念前置，信息克制，善用倒计时与不可靠叙述。','推理,惊悚',1,NOW()),
(0,'幽默诙谐','吐槽与反差制造笑点，对白轻快。','轻松,搞笑',1,NOW()),
(0,'爽文','节奏明快，主角逆袭打脸，冲突密集，章末留钩子。','玄幻,都市',0,NOW());

INSERT INTO `kl_prompts` (`user_id`,`name`,`content`,`type`,`variables`,`created_at`) VALUES
(0,'MCP 角色规划','为小说《{title}》设计核心角色。题材：{genre}；主题：{theme}；时代背景：{time_period}；地理位置：{location}。请先使用可用工具检索参考资料，再输出 5 位角色的姓名、身份、欲望、缺陷与人物弧光。','mcp','{title},{genre},{theme},{time_period},{location}',NOW()),
(0,'MCP 世界观规划','为小说《{title}》构建世界观。题材：{genre}；主题：{theme}；简介：{description}。请输出地理格局、权力结构、力量体系、资源冲突与三条可延展的世界线索。','mcp','{title},{genre},{theme},{description}',NOW()),
(0,'MCP 工具测试','这是一个用于测试自定义提示词与工具调用的模板。请回显以下变量并说明你实际调用了哪些工具：{description}','mcp','{description}',NOW());

INSERT INTO `kl_skills` (`name`,`type`,`description`,`triggers`,`config_path`,`enabled`) VALUES
('网文去 AI 味','润色','检测并清除文本中的 AI 写作痕迹：套话、排比堆砌、空泛形容与总结式收尾。','/story-deslop,去 AI 味,去味','skills/story-deslop.json',1),
('长篇网文拆文','长篇','深度拆解爆款长篇的黄金三章、人设架构、爽点设计与节奏控制。','/story-long-analyze,帮我拆这本书','skills/story-long-analyze.json',1),
('长篇网文扫榜','长篇','分析起点、番茄、晋江等平台排行，提炼当下热门题材与开局套路。','/story-long-scan,长篇什么火,起点排行','skills/story-long-scan.json',1),
('长篇网文写作','长篇','从大纲到正文，辅助长篇网络小说的连续创作与伏笔管理。','/story-long-write,写长篇,继续写','skills/story-long-write.json',1);

INSERT INTO `kl_mcp_plugins` (`user_id`,`name`,`url`,`type`,`created_at`) VALUES
(0,'MCP 测试插件示例','https://example.com/mcp','http',NOW());
