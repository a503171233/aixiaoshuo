const $ = (id) => document.getElementById(id);
const state = { driver: 'mysql', tables: [], mode: 'keep' };

function show(step) {
  [1, 2, 3, 4].forEach((n) => $('step' + n)?.classList.toggle('hidden', n !== step));
  document.querySelectorAll('#steps li').forEach((li) => {
    const n = Number(li.dataset.step);
    li.classList.toggle('active', n === step);
    li.classList.toggle('done', n < step);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function alertHtml(type, text) {
  return '<p class="alert ' + type + '">' + text + '</p>';
}

async function post(action, payload) {
  const res = await fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload || {}),
  });
  try {
    return await res.json();
  } catch (e) {
    return { ok: false, message: '服务器返回异常（HTTP ' + res.status + '）' };
  }
}

function dbPayload() {
  return {
    driver: state.driver,
    host: $('host').value.trim(),
    port: $('port').value.trim(),
    database: $('database').value.trim(),
    username: $('username').value.trim(),
    password: $('password').value,
    prefix: $('prefix').value.trim(),
    auto_create: $('autoCreate').checked,
  };
}

$('toStep2')?.addEventListener('click', () => show(2));
$('backTo1')?.addEventListener('click', () => show(1));
$('backTo2')?.addEventListener('click', () => show(2));

document.querySelectorAll('input[name=driver]').forEach((radio) => {
  radio.addEventListener('change', () => {
    state.driver = radio.value;
    document.querySelectorAll('.driver').forEach((el) => el.classList.toggle('active', el.contains(radio) === false ? false : true));
    document.querySelectorAll('.driver').forEach((el) => {
      const input = el.querySelector('input');
      el.classList.toggle('active', input.checked);
    });
    $('mysqlFields').classList.toggle('hidden', radio.value === 'sqlite');
    $('autoCreate').parentElement.classList.toggle('hidden', radio.value === 'sqlite');
    $('toStep3').classList.add('hidden');
    $('testMsg').innerHTML = '';
  });
});

$('testBtn')?.addEventListener('click', async () => {
  $('testBtn').disabled = true;
  $('testMsg').innerHTML = alertHtml('warn', '正在测试数据库连接…');
  const data = await post('test', dbPayload());
  $('testBtn').disabled = false;
  if (!data.ok) {
    $('testMsg').innerHTML = alertHtml('error', data.message);
    $('toStep3').classList.add('hidden');
    return;
  }
  state.tables = data.tables || [];
  $('testMsg').innerHTML = alertHtml('success', data.message);
  $('toStep3').classList.remove('hidden');
});

$('toStep3')?.addEventListener('click', () => {
  if (state.tables.length) {
    $('tableWarn').innerHTML =
      alertHtml('warn', '检测到数据库中已存在 ' + state.tables.length + ' 张同前缀数据表，请选择安装方式：') +
      '<div class="modes">' +
      '<label><input type="radio" name="mode" value="keep" checked> 保留数据（跳过已有表的初始数据）</label>' +
      '<label><input type="radio" name="mode" value="overwrite"> 覆盖安装（删除旧表并重新导入）</label>' +
      '</div>';
    document.querySelectorAll('input[name=mode]').forEach((r) =>
      r.addEventListener('change', () => { state.mode = r.value; })
    );
  } else {
    $('tableWarn').innerHTML = '';
    state.mode = 'keep';
  }
  show(3);
});

$('importBtn')?.addEventListener('click', async () => {
  const adminUser = $('adminUser').value.trim();
  const adminPass = $('adminPass').value;
  if (!adminUser) { $('importMsg').innerHTML = alertHtml('error', '请填写管理员用户名'); return; }
  if (adminPass.length < 6) { $('importMsg').innerHTML = alertHtml('error', '管理员密码至少 6 位'); return; }
  $('importBtn').disabled = true;
  $('importMsg').innerHTML = alertHtml('warn', '正在导入数据库结构与初始数据，请稍候…');
  const data = await post('import', { mode: state.mode, admin_user: adminUser, admin_pass: adminPass });
  $('importBtn').disabled = false;
  if (!data.ok) { $('importMsg').innerHTML = alertHtml('error', data.message); return; }
  $('importMsg').innerHTML = alertHtml('success', data.message);
  $('toStep4').classList.remove('hidden');
});

$('toStep4')?.addEventListener('click', () => show(4));

$('finishBtn')?.addEventListener('click', async () => {
  $('finishBtn').disabled = true;
  $('finishMsg').innerHTML = alertHtml('warn', '正在写入配置文件…');
  const data = await post('finish', {});
  if (!data.ok) {
    $('finishBtn').disabled = false;
    $('finishMsg').innerHTML = alertHtml('error', data.message);
    return;
  }
  $('finishMsg').innerHTML = alertHtml('success', data.message + ' 安装向导已锁定，现在可以开始创作。');
  $('finishBtn').classList.add('hidden');
  $('goHome').classList.remove('hidden');
  $('goLogin').classList.remove('hidden');
});
