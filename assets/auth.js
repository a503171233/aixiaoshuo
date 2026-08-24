(function () {
  const tabs = document.getElementById('authTabs');
  const form = document.getElementById('authForm');
  const invite = document.getElementById('inviteField');
  const alertBox = document.getElementById('authAlert');
  const submit = document.getElementById('authSubmit');
  let mode = 'login';

  tabs.addEventListener('click', (e) => {
    const btn = e.target.closest('.tab');
    if (!btn) return;
    mode = btn.dataset.mode;
    tabs.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t === btn));
    invite.classList.toggle('hidden', mode !== 'register');
    submit.textContent = mode === 'register' ? '注册并登录' : '登录';
    alertBox.classList.add('hidden');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());
    submit.disabled = true;
    submit.textContent = mode === 'register' ? '注册中…' : '登录中…';
    try {
      const res = await fetch('/api.php?action=' + mode, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      const json = await res.json();
      if (json.ok) {
        alertBox.className = 'alert ok';
        alertBox.textContent = json.message || '成功';
        location.href = 'index.php';
        return;
      }
      alertBox.className = 'alert err';
      alertBox.textContent = json.message || '操作失败';
    } catch (err) {
      alertBox.className = 'alert err';
      alertBox.textContent = '网络错误：' + err.message;
    }
    submit.disabled = false;
    submit.textContent = mode === 'register' ? '注册并登录' : '登录';
  });
})();
