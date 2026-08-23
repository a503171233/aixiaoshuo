<?php
declare(strict_types=1);

define('KL_ROOT', dirname(__DIR__));
$lockFile = KL_ROOT . '/config/install.lock';
$installed = is_file($lockFile);

foreach (['uploads', 'data', 'config'] as $dir) {
    if (!is_dir(KL_ROOT . '/' . $dir)) {
        @mkdir(KL_ROOT . '/' . $dir, 0777, true);
    }
}

$checks = [
    ['name' => 'PHP 版本 ≥ 8.0', 'pass' => PHP_VERSION_ID >= 80000, 'value' => PHP_VERSION, 'fix' => '请升级 PHP 到 8.0 及以上版本。'],
    ['name' => 'PDO 扩展', 'pass' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? '已启用' : '未启用', 'fix' => '在 php.ini 中开启 extension=pdo。'],
    ['name' => 'pdo_mysql 扩展', 'pass' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? '已启用' : '未启用', 'fix' => '在 php.ini 中开启 extension=pdo_mysql。'],
    ['name' => 'fileinfo 扩展', 'pass' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? '已启用' : '未启用', 'fix' => '在 php.ini 中开启 extension=fileinfo，用于上传文件类型判断。'],
    ['name' => 'curl 扩展', 'pass' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? '已启用' : '未启用', 'fix' => '在 php.ini 中开启 extension=curl，用于调用 AI 接口。'],
    ['name' => 'uploads 目录可写', 'pass' => is_writable(KL_ROOT . '/uploads'), 'value' => is_writable(KL_ROOT . '/uploads') ? '可写' : '不可写', 'fix' => '执行 chmod 755 uploads 或赋予 Web 用户写权限。'],
    ['name' => 'data 目录可写', 'pass' => is_writable(KL_ROOT . '/data'), 'value' => is_writable(KL_ROOT . '/data') ? '可写' : '不可写', 'fix' => '执行 chmod 755 data 或赋予 Web 用户写权限。'],
    ['name' => 'config 目录可写', 'pass' => is_writable(KL_ROOT . '/config'), 'value' => is_writable(KL_ROOT . '/config') ? '可写' : '不可写', 'fix' => '执行 chmod 755 config 或赋予 Web 用户写权限。'],
    ['name' => '未检测到安装锁文件', 'pass' => !$installed, 'value' => $installed ? '已存在 install.lock' : '未安装', 'fix' => '若需重新安装，请备份数据后手动删除 config/install.lock。'],
];
$envPass = array_reduce($checks, static fn($carry, $item) => $carry && $item['pass'], true);
$sqliteAvailable = extension_loaded('pdo_sqlite');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 · KIrva 小说助手</title>
<link rel="stylesheet" href="install.css">
</head>
<body>
<div class="shell">
  <header class="head">
    <div class="brand"><span class="logo">K</span><span>KIrva 小说助手</span></div>
    <span class="ver">v1.1.7 安装向导</span>
  </header>

  <ol class="steps" id="steps">
    <li class="active" data-step="1"><b>1</b>环境检测</li>
    <li data-step="2"><b>2</b>数据库配置</li>
    <li data-step="3"><b>3</b>导入数据库</li>
    <li data-step="4"><b>4</b>完成安装</li>
  </ol>

  <?php if ($installed): ?>
  <section class="panel">
    <h2>系统已安装</h2>
    <p class="lead">检测到 <code>config/install.lock</code>，安装向导已被锁定，防止重复安装覆盖数据。</p>
    <p class="lead">如需重新安装，请先<b>备份现有数据</b>，然后手动删除该锁文件并刷新本页。</p>
    <div class="row"><a class="btn primary" href="../index.php">进入首页</a><a class="btn" href="../login.php">进入登录页</a></div>
  </section>
  <?php else: ?>

  <section class="panel" id="step1">
    <h2>第一步 · 环境检测</h2>
    <p class="lead">安装程序会检查运行环境与目录权限，全部通过后才能继续。</p>
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
      <li class="<?= $c['pass'] ? 'pass' : 'fail' ?>">
        <span class="dot"></span>
        <span class="cname"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="cval"><?= htmlspecialchars($c['value'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (!$c['pass']): ?><span class="cfix">解决方法：<?= htmlspecialchars($c['fix'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$envPass): ?><p class="alert error">存在未通过的检测项，请按提示修复后刷新页面。</p><?php endif; ?>
    <div class="row"><button class="btn primary" id="toStep2" <?= $envPass ? '' : 'disabled' ?>>下一步：数据库配置</button></div>
  </section>

  <section class="panel hidden" id="step2">
    <h2>第二步 · 数据库配置</h2>
    <p class="lead">填写数据库连接信息，测试连接成功后进入导入环节。字符集固定 utf8mb4 / utf8mb4_unicode_ci。</p>
    <div class="drivers">
      <label class="driver active"><input type="radio" name="driver" value="mysql" checked><b>MySQL 5.6+</b><span>生产部署推荐，需要可访问的 MySQL 服务</span></label>
      <label class="driver <?= $sqliteAvailable ? '' : 'disabled' ?>"><input type="radio" name="driver" value="sqlite" <?= $sqliteAvailable ? '' : 'disabled' ?>><b>SQLite 演示模式</b><span>无 MySQL 环境时本地体验，数据存于 data/ 目录</span></label>
    </div>
    <div class="grid" id="mysqlFields">
      <label>数据库主机<input id="host" value="127.0.0.1" autocomplete="off"></label>
      <label>数据库端口<input id="port" value="3306" autocomplete="off"></label>
      <label>数据库名称<input id="database" value="klrvai_novel" autocomplete="off"></label>
      <label>数据库用户名<input id="username" value="root" autocomplete="off"></label>
      <label>数据库密码<input id="password" type="password" autocomplete="new-password"></label>
      <label>数据表前缀<input id="prefix" value="kl_" autocomplete="off"></label>
    </div>
    <label class="check"><input type="checkbox" id="autoCreate" checked>数据库不存在时自动创建（需当前账号具备 CREATE 权限）</label>
    <div id="testMsg"></div>
    <div class="row">
      <button class="btn" id="backTo1">上一步</button>
      <button class="btn primary" id="testBtn">测试连接</button>
      <button class="btn primary hidden" id="toStep3">下一步：导入数据库</button>
    </div>
  </section>

  <section class="panel hidden" id="step3">
    <h2>第三步 · 导入数据库</h2>
    <p class="lead">安装程序将读取 install.sql，按前缀替换表名后依次建表，并写入七种预设风格、系统提示词模板、内置技能与 MCP 示例插件。</p>
    <div id="tableWarn"></div>
    <div class="grid">
      <label>管理员用户名<input id="adminUser" value="admin" autocomplete="off"></label>
      <label>管理员密码<input id="adminPass" type="password" placeholder="至少 6 位" autocomplete="new-password"></label>
    </div>
    <div id="importMsg"></div>
    <div class="row">
      <button class="btn" id="backTo2">上一步</button>
      <button class="btn primary" id="importBtn">开始导入</button>
      <button class="btn primary hidden" id="toStep4">下一步：完成安装</button>
    </div>
  </section>

  <section class="panel hidden" id="step4">
    <h2>第四步 · 完成安装</h2>
    <p class="lead">写入 config/config.php 配置文件并生成 install.lock 锁文件，防止他人重复安装。</p>
    <div id="finishMsg"></div>
    <div class="row">
      <button class="btn primary" id="finishBtn">写入配置并完成安装</button>
      <a class="btn primary hidden" id="goHome" href="../index.php">进入首页</a>
      <a class="btn hidden" id="goLogin" href="../login.php">进入登录页</a>
    </div>
  </section>

  <?php endif; ?>
</div>
<script src="install.js"></script>
</body>
</html>
