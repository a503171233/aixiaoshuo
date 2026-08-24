# KIrva 小说助手

## 项目简介
KIrva 小说助手是一款基于 **PHP 8.x**、**MySQL**（或 SQLite 演示模式）实现的轻量级小说创作管理平台。它提供了 **作品管理、拆书导入、灵感生成、写作风格润色、技能插件** 等完整功能，支持 **OpenAI 兼容 API**，可自行配置模型地址和密钥，实现完全的自持 AI 能力。

## 主要功能
- **安装向导**：四步完成环境检测、数据库配置、数据导入、初始化锁定。
- **用户系统**：注册、登录、每日签到、邀请奖励、积分与等级系统。
- **作品管理**：书架展示、章节进度、自动统计字数（万/字）等。
- **拆书导入**：上传 TXT，后台异步拆分章节并生成大纲、世界观。
- **灵感创作器**：根据选定或自定义小说类型生成书名、简介、主题、章节大纲。
- **写作风格库**：七套预设风格，可在编辑页面即时切换。
- **技能管理**：内置网文去 AI 味、长篇拆解、排行榜扫描、章节续写等，支持自定义技能 JSON。
- **提示词模板**：系统预设与用户自建模板，支持变量 `{title}`、`{genre}`、`{theme}` 等。
- **MCP 插件**：提供 HTTP、流式 HTTP、SSE 三种插件类型，支持调用外部服务并统计调用情况。
- **深色模式**：主题切换即时生效并持久化至 `localStorage`。

## 技术栈
- **后端**：PHP 8.x + PDO（MySQL / SQLite）
- **前端**：原生 HTML、CSS、JavaScript（SPA，基于 hash 路由）
- **AI 接口**：兼容 OpenAI `ChatCompletions`，可自行配置 `api_base`、`api_key`、`model`、`temperature`、`max_tokens`。
- **部署**：使用 `php -S 127.0.0.1:8910 -t /workspace` 启动内置开发服务器。

## 安装与部署步骤
1. **克隆仓库并进入项目根目录**
   ```bash
   git clone <repo_url>
   cd ki-rva-novel-assistant
   ```
2. **检查运行环境**（PHP >= 8.0，已开启 PDO、pdo_mysql、fileinfo）
   ```bash
   php -r "echo PHP_VERSION;"
   ```
3. **创建配置文件**
   - 复制 `config/config.sample.php`（如果存在）为 `config/config.php`，或直接在 `config/` 目录下新建 `config.php`，内容示例：
   ```php
   <?php
   return [
       'app_key' => 'your-secret-key',
       'db' => [
           'driver'   => 'mysql',             // 或 'sqlite'（演示模式）
           'host'     => '127.0.0.1',
           'port'     => 3306,
           'dbname'   => 'klrvai_novel',
           'username' => 'root',
           'password' => '',
           'prefix'   => 'kl_',
       ],
   ];
   ```
4. **运行安装向导**（首次部署）
   - 启动 PHP 开发服务器
     ```bash
     php -d xdebug.mode=off -S 127.0.0.1:8910 -t /workspace
     ```
   - 在浏览器访问 `http://127.0.0.1:8910/install/index.php`，按照四步完成环境检测、数据库配置、SQL 导入、完成初始化。
   - 成功后系统会生成 `install.lock`，以后直接访问 `http://127.0.0.1:8910/login.php` 即可。
5. **账号注册 / 登录**
   - 初始管理员账号可在 `install.sql` 中的 `users` 表里自行插入，也可使用注册页面自行创建。
6. **模型配置**（可选）
   - 进入 “设置” 页面，填写 OpenAI 兼容接口地址、API Key、模型名称等，点击 “保存”。
7. **使用功能**
   - 登录后即可进入书架、拆书、灵感、生成功能等全部模块。所有数据均保存在 `data/` 目录下的 SQLite 文件或 MySQL 数据库中。

## 常见问题
- **登录后出现 JSON 解析错误**：确保 `assets/auth.js` 中的请求路径已改为绝对路径 `fetch('/api.php?action=' + mode, ...)`，并重启服务器。
- **拆书任务卡住**：检查 `model_save` 中的 `api_base` 是否指向可达的 Mock 或真实 OpenAI 接口。
- **深色模式未持久化**：`localStorage` 中的 `kl_theme` 键保存主题，清除浏览器缓存后可重新切换。

## 许可证
本项目采用 MIT 许可证，详情请参阅 `LICENSE` 文件。

---
*本文档根据项目实际代码自动生成，若有更新请同步修改对应章节。*