<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!kl_is_installed()) {
    header('Location: install/index.php');
    exit;
}

kl_start_session();
if (kl_user_id()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 · <?= kl_e(KL_APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-body">
<main class="auth-wrap">
  <section class="auth-hero">
    <div class="auth-brand"><span class="logo">K</span><?= kl_e(KL_APP_NAME) ?></div>
    <h1>把灵感写成长篇</h1>
    <p>AI 拆书导入、灵感生成、风格润色与技能工作流，一处管理你的所有作品。</p>
    <ul class="auth-points">
      <li>上传 TXT 自动识别章节，生成大纲与世界观</li>
      <li>七种预设写作风格，随时切换叙事质感</li>
      <li>OpenAI 兼容接口，模型与密钥完全自持</li>
    </ul>
    <span class="auth-ver">v<?= kl_e(KL_VERSION) ?></span>
  </section>

  <section class="auth-card">
    <div class="tabs" id="authTabs">
      <button class="tab active" data-mode="login" type="button">登录</button>
      <button class="tab" data-mode="register" type="button">注册</button>
    </div>

    <form id="authForm" autocomplete="off">
      <label class="field">
        <span>用户名</span>
        <input type="text" name="username" placeholder="请输入用户名" required maxlength="20">
      </label>
      <label class="field">
        <span>密码</span>
        <input type="password" name="password" placeholder="至少 6 位" required minlength="6">
      </label>
      <label class="field hidden" id="inviteField">
        <span>邀请码（选填）</span>
        <input type="text" name="invite_code" placeholder="填写邀请码，邀请人可得 300 积分" maxlength="16">
      </label>
      <div class="alert hidden" id="authAlert"></div>
      <button class="btn primary block" id="authSubmit" type="submit">登录</button>
    </form>
    <p class="auth-foot">首次部署？请先访问 <a href="install/index.php">安装向导</a> 完成初始化。</p>
  </section>
</main>
<script src="assets/auth.js"></script>
</body>
</html>
