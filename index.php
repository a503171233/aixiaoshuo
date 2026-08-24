<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!kl_is_installed()) {
    header('Location: install/index.php');
    exit;
}

kl_start_session();
if (!kl_user_id()) {
    header('Location: login.php');
    exit;
}
$user = kl_current_user();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= kl_e(KL_APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="side-brand"><span class="logo">K</span><b><?= kl_e(KL_APP_NAME) ?></b></div>
    <nav class="nav" id="nav">
      <button class="nav-item active" data-view="shelf"><i>书</i>书架</button>
      <button class="nav-item" data-view="split"><i>拆</i>拆书导入</button>
      <button class="nav-item" data-view="idea"><i>灵</i>灵感创作器</button>
      <button class="nav-item" data-view="write"><i>写</i>章节续写</button>
      <button class="nav-item" data-view="style"><i>格</i>写作风格库</button>
      <button class="nav-item" data-view="skill"><i>技</i>技能管理</button>
      <button class="nav-item" data-view="prompt"><i>词</i>提示词模板</button>
      <button class="nav-item" data-view="mcp"><i>插</i>MCP 插件</button>
      <button class="nav-item" data-view="setting"><i>设</i>设置</button>
      <button class="nav-item" data-view="mine"><i>我</i>我的</button>
    </nav>
    <div class="side-foot">v<?= kl_e(KL_VERSION) ?></div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div>
        <h1 id="viewTitle">书架</h1>
        <p id="viewLead">管理你的全部作品，随时查看进度。</p>
      </div>
      <div class="topbar-right">
        <button class="icon-btn" id="themeToggle" title="切换深色模式">🌙</button>
        <div class="me"><span class="avatar"><?= kl_e(mb_substr((string)$user['username'], 0, 1)) ?></span><span><?= kl_e((string)$user['username']) ?></span></div>
        <button class="btn ghost" id="logoutBtn">退出</button>
      </div>
    </header>

    <div class="toast hidden" id="toast"></div>

    <!-- 书架 -->
    <section class="view active" data-view="shelf">
      <div class="stats" id="shelfStats"></div>
      <div class="bar">
        <span class="bar-title">我的作品</span>
        <button class="btn primary" id="newBookBtn">+ 新建作品</button>
      </div>
      <div class="cards" id="bookList"></div>
    </section>

    <!-- 拆书导入 -->
    <section class="view" data-view="split">
      <div class="panel">
        <h2>上传 TXT</h2>
        <p class="lead">上传 TXT 后，AI 智能识别章节，自动生成大纲与世界观，一键导入书架。单文件上限 50MB。</p>
        <label class="uploader" id="dropZone">
          <input type="file" id="txtFile" accept=".txt" hidden>
          <b>点击选择 TXT 文件</b>
          <span id="fileInfo">尚未选择文件</span>
        </label>
        <div class="field-row">
          <label class="field">
            <span>解析范围</span>
            <select id="parseScope">
              <option value="tail">截取末尾章节（推荐，AI 消耗低）</option>
              <option value="all">整本拆解（完整解析，AI 消耗较大）</option>
            </select>
          </label>
          <label class="field" id="chapterCountField">
            <span>截取章节数</span>
            <select id="chapterCount">
              <option value="10">末 10 章</option>
              <option value="15">末 15 章</option>
              <option value="20" selected>末 20 章</option>
              <option value="25">末 25 章</option>
              <option value="30">末 30 章</option>
              <option value="40">末 40 章</option>
            </select>
          </label>
        </div>
        <button class="btn primary" id="submitTask">提交拆书任务</button>
      </div>
      <div class="panel">
        <div class="bar">
          <span class="bar-title">后台任务</span>
          <button class="btn ghost" id="refreshTasks">刷新</button>
        </div>
        <div class="list" id="taskList"></div>
      </div>
    </section>
<!-- 灵感创作器 -->
    <section class="view" data-view="idea">
      <div class="panel">
        <h2>小说灵感创作器</h2>
        <p class="lead">选择类型后由 AI 生成书名、简介、主题与大纲，可多选类型融合，也可手动补充后再生成。</p>
        <label class="field"><span>书名（可留空由 AI 生成）</span><input type="text" id="ideaTitle" placeholder="例如：当斗罗来了个道士"></label>
        <label class="field"><span>简介（可留空）</span><textarea id="ideaIntro" rows="3" placeholder="一句话概述你的构想"></textarea></label>
        <label class="field"><span>主题（可留空）</span><input type="text" id="ideaTheme" placeholder="例如：逆袭、复仇、守护"></label>
        <div class="field">
          <span>小说类型（至少选择一种，可多选融合）</span>
          <div class="chips" id="genreChips"></div>
          <div class="chip-add">
            <input type="text" id="customGenre" placeholder="自定义类型" maxlength="10">
            <button class="btn ghost" id="addGenre" type="button">添加</button>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn primary" id="genIdea">AI 灵感生成</button>
          <button class="btn ghost" id="clearIdea">清空</button>
          <button class="btn ghost" id="saveIdea">存入书架</button>
        </div>
        <div class="result hidden" id="ideaResult"></div>
      </div>
    </section>

    <!-- 章节续写 / 风格润色 -->
    <section class="view" data-view="write">
      <div class="panel">
        <h2>章节续写</h2>
        <p class="lead">结合作品大纲、世界观、写作风格与技能触发词生成正文。</p>
        <div class="field-row">
          <label class="field"><span>选择作品</span><select id="writeBook"></select></label>
          <label class="field"><span>写作风格</span><select id="writeStyle"></select></label>
          <label class="field"><span>技能触发词（选填）</span><input type="text" id="writeSkill" placeholder="如 /story-long-write"></label>
        </div>
        <label class="field"><span>续写需求</span><textarea id="writeReq" rows="3" placeholder="例如：主角初入宗门，被长老当众刁难"></textarea></label>
        <button class="btn primary" id="genWrite">生成正文</button>
        <div class="result hidden" id="writeResult"></div>
      </div>
      <div class="panel">
        <h2>风格润色</h2>
        <p class="lead">选择风格后，AI 按该风格改写你粘贴的文本。</p>
        <div class="field-row">
          <label class="field"><span>润色风格</span><select id="polishStyle"></select></label>
        </div>
        <label class="field"><span>待润色文本</span><textarea id="polishText" rows="5" placeholder="粘贴需要改写的段落"></textarea></label>
        <button class="btn primary" id="genPolish">按风格润色</button>
        <div class="result hidden" id="polishResult"></div>
      </div>
    </section>

    <!-- 风格库 -->
    <section class="view" data-view="style">
      <div class="bar">
        <div class="tabs" id="styleTabs">
          <button class="tab active" data-tab="all">全部</button>
          <button class="tab" data-tab="preset">预设</button>
          <button class="tab" data-tab="mine">我的</button>
        </div>
        <div class="bar-right"><span class="count" id="styleCount">共 0 个</span><button class="btn primary" id="newStyle">+ 新增风格</button></div>
      </div>
      <div class="cards" id="styleList"></div>
    </section>

    <!-- 技能管理 -->
    <section class="view" data-view="skill">
      <div class="panel">
        <h2>技能工作流</h2>
        <p class="lead">技能由后端 skills 目录加载，数据库存储元信息与启用状态。章节生成时输入触发词即可一键应用。</p>
      </div>
      <div class="cards" id="skillList"></div>
      <div class="panel">
        <h2>技能试运行</h2>
        <div class="field-row">
          <label class="field"><span>触发词</span><input type="text" id="skillTrigger" placeholder="如 去 AI 味 / /story-long-scan"></label>
        </div>
        <label class="field"><span>附加内容（选填）</span><textarea id="skillText" rows="4" placeholder="粘贴待处理文本"></textarea></label>
        <button class="btn primary" id="runSkill">执行技能</button>
        <div class="result hidden" id="skillResult"></div>
      </div>
    </section>

    <!-- 提示词模板 -->
    <section class="view" data-view="prompt">
      <div class="bar">
        <div class="tabs" id="promptTabs">
          <button class="tab active" data-tab="all">全部提示词</button>
          <button class="tab" data-tab="mine">我的创作</button>
        </div>
        <div class="bar-right">
          <input type="search" id="promptSearch" placeholder="按名称搜索模板">
          <button class="btn primary" id="newPrompt">+ 新建模板</button>
        </div>
      </div>
      <div class="cards" id="promptList"></div>
      <div class="result hidden" id="promptRunResult"></div>
    </section>

    <!-- MCP -->
    <section class="view" data-view="mcp">
      <div class="stats" id="mcpStats"></div>
      <div class="bar">
        <span class="bar-title">已接入插件</span>
        <button class="btn primary" id="newMcp">+ 添加插件</button>
      </div>
      <div class="cards" id="mcpList"></div>
    </section>

    <!-- 设置 -->
    <section class="view" data-view="setting">
      <div class="panel">
        <h2>外观</h2>
        <div class="switch-row">
          <div><b>深色模式</b><span class="lead">立即切换主题，偏好保存在本地浏览器。</span></div>
          <label class="switch"><input type="checkbox" id="darkSwitch"><i></i></label>
        </div>
      </div>
      <div class="panel">
        <h2>模型配置（OpenAI 兼容）</h2>
        <p class="lead">所有 AI 调用均走此配置，密钥加密存储、页面以星号展示。</p>
        <div class="field-row">
          <label class="field"><span>接口类型</span>
    <select id="mInterface">
        <option value="official" selected>官方接口</option>
        <option value="custom">Openapi兼容接口</option>
        <option value="zhipu">智谱清言</option>
        <option value="deepseek">Deep Seek</option>
    </select>
</label>
          <label class="field" id="rowBase"><span>API 地址</span><input type="text" id="mBase" placeholder="https://api.example.com/v1"></label>
        </div>
        <div class="field-row">
          <label class="field"><span>API 密钥</span><input type="text" id="mKey" placeholder="sk-..."></label>
          <label class="field">
            <span>模型名称</span>
            <div class="inline">
              <input type="text" id="mModel" placeholder="deepseek-chat" list="modelOptions">
              <datalist id="modelOptions"></datalist>
              <button class="btn ghost" id="fetchModels" type="button">拉取模型</button>
            </div>
          </label>
        </div>
        <div class="field-row">
          <label class="field"><span>温度（0 - 2）</span><input type="text" id="mTemp" placeholder="0.7"></label>
          <label class="field"><span>最大 Token（0 表示不设上限）</span><input type="text" id="mTokens" placeholder="129000"></label>
        </div>
        <label class="field"><span>系统提示词</span><textarea id="mSystem" rows="3" placeholder="每次 AI 调用都会附带"></textarea></label>
        <button class="btn primary" id="saveModel">保存配置</button>
      </div>
    </section>

    <!-- 我的 -->
    <section class="view" data-view="mine">
      <div class="panel profile" id="profileCard"></div>
      <div class="panel">
        <h2>每日签到</h2>
        <p class="lead">每天可领取 100 积分，同一天仅可领取一次。</p>
        <button class="btn primary" id="checkinBtn">每日领取</button>
      </div>
      <div class="panel">
        <h2>邀请奖励</h2>
        <p class="lead">邀请 1 人得 300 积分，上限 30 人。将邀请码或链接发给好友，注册时填写即可。</p>
        <div class="invite" id="inviteBox"></div>
      </div>
      <div class="panel">
        <h2>账户安全</h2>
        <div class="field-row">
          <label class="field"><span>原密码</span><input type="password" id="oldPwd"></label>
          <label class="field"><span>新密码（至少 6 位）</span><input type="password" id="newPwd"></label>
        </div>
        <button class="btn primary" id="changePwd">修改密码</button>
        <div class="bar"><span class="bar-title">最近登录记录</span></div>
        <div class="list" id="logList"></div>
      </div>
      <div class="panel">
        <h2>检测更新 / 关于</h2>
        <p class="lead" id="aboutLine"><?= kl_e(KL_APP_NAME) ?> · 当前 <?= kl_e(KL_VERSION) ?> · © <?= date('Y') ?></p>
        <button class="btn ghost" id="checkUpdate">检查更新</button>
      </div>
    </section>
  </main>
</div>

<div class="modal hidden" id="modal">
  <div class="modal-box">
    <div class="modal-head"><b id="modalTitle">标题</b><button class="icon-btn" id="modalClose">✕</button></div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot">
      <button class="btn ghost" id="modalCancel">取消</button>
      <button class="btn primary" id="modalOk">保存</button>
    </div>
  </div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
