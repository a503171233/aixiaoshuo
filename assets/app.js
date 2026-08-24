const VIEW_META = {
  shelf: ['书架', '管理你的全部作品，随时查看进度。'],
  split: ['拆书导入', '上传 TXT 后，AI 智能识别章节，自动生成大纲与世界观，一键导入书架。'],
  idea: ['灵感创作器', '选择类型，让 AI 生成书名、简介、主题与大纲。'],
  write: ['写作台', '章节续写与风格润色，结合大纲、世界观、风格与技能。'],
  style: ['写作风格库', '系统预设风格与自定义风格，创作时可随时套用。'],
  skill: ['技能管理', '管理创作技能，章节生成时可一键应用。'],
  prompt: ['提示词模板', '预设模板全员可见，自定义模板仅本人可见。'],
  mcp: ['MCP 插件', '接入自定义 MCP 服务器，为创作助手提供更多工具。'],
  setting: ['设置', '外观偏好与 OpenAI 兼容模型配置。'],
  mine: ['我的', '积分、等级、邀请奖励与账户安全。'],
};
const modelSelect = document.querySelector('#modelSelect');
const providerSelect = document.querySelector('#providerSelect');

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const state = {
  books: [],
  styles: [],
  prompts: [],
  skills: [],
  mcps: [],
  genres: ['玄幻', '都市', '历史', '科幻', '武侠', '仙侠', '奇幻', '悬疑'],
  picked: new Set(),
  styleTab: 'all',
  promptTab: 'all',
  profile: null,
  taskTimer: null,
};

function esc(str) {
  return String(str == null ? '' : str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function formatWords(n) {
  const num = Number(n) || 0;
  if (num >= 10000) return (num / 10000).toFixed(num % 10000 === 0 ? 0 : 1) + 'W';
  return String(num);
}

function coverStyle(title) {
  const seed = String(title || '书').charCodeAt(0) || 66;
  const hue = seed % 360;
  return `background:linear-gradient(135deg,hsl(${hue} 62% 52%),hsl(${(hue + 42) % 360} 68% 62%))`;
}

let toastTimer = null;
function toast(message, type = 'ok') {
  const box = $('#toast');
  box.textContent = message;
  box.className = 'toast ' + type;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { box.className = 'toast hidden'; }, 2600);
}

async function api(action, data, isForm = false) {
  const opt = { method: data === undefined ? 'GET' : 'POST' };
  if (data !== undefined) {
    if (isForm) opt.body = data;
    else {
      opt.headers = { 'Content-Type': 'application/json' };
      opt.body = JSON.stringify(data);
    }
  }
  const res = await fetch('api.php?action=' + action, opt);
  if (res.status === 401) {
    location.href = 'login.php';
    throw new Error('未登录');
  }
  let json;
  try {
    json = await res.json();
  } catch (e) {
    throw new Error('服务端返回异常，请检查 PHP 错误日志');
  }
  if (!json.ok) throw new Error(json.message || '操作失败');
  return json;
}

async function guard(btn, fn, busyText = '处理中…') {
  const raw = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = busyText; }
  try {
    await fn();
  } catch (e) {
    toast(e.message || '操作失败', 'err');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = raw; }
  }
}
/* ---------------- 主题 ---------------- */
function applyTheme(dark) {
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  localStorage.setItem('kl_theme', dark ? 'dark' : 'light');
  $('#themeToggle').textContent = dark ? '☀️' : '🌙';
  const sw = $('#darkSwitch');
  if (sw) sw.checked = dark;
}

function initTheme() {
  const dark = localStorage.getItem('kl_theme') === 'dark';
  applyTheme(dark);
  $('#themeToggle').addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.theme !== 'dark');
  });
  $('#darkSwitch').addEventListener('change', (e) => applyTheme(e.target.checked));
}

/* ---------------- 弹窗 ---------------- */
let modalHandler = null;
function openModal(title, bodyHtml, onOk, okText = '保存') {
  $('#modalTitle').textContent = title;
  $('#modalBody').innerHTML = bodyHtml;
  $('#modalOk').textContent = okText;
  $('#modal').classList.remove('hidden');
  modalHandler = onOk;
  const first = $('#modalBody input, #modalBody textarea, #modalBody select');
  if (first) first.focus();
}

function closeModal() {
  $('#modal').classList.add('hidden');
  modalHandler = null;
}

function initModal() {
  $('#modalClose').addEventListener('click', closeModal);
  $('#modalCancel').addEventListener('click', closeModal);
  $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
  $('#modalOk').addEventListener('click', () => {
    if (!modalHandler) return closeModal();
    guard($('#modalOk'), async () => {
      await modalHandler();
      closeModal();
    }, '保存中…');
  });
}

function modalValue(id) {
  const el = $('#' + id);
  return el ? el.value.trim() : '';
}
/* ---------------- 视图路由 ---------------- */
const loaders = {};

async function switchView(name) {
  $$('.nav-item').forEach((el) => el.classList.toggle('active', el.dataset.view === name));
  $$('.view').forEach((el) => el.classList.toggle('active', el.dataset.view === name));
  const meta = VIEW_META[name] || ['', ''];
  $('#viewTitle').textContent = meta[0];
  $('#viewLead').textContent = meta[1];
  location.hash = '#' + name;
  if (name !== 'split' && state.taskTimer) {
    clearInterval(state.taskTimer);
    state.taskTimer = null;
  }
  if (loaders[name]) {
    try { await loaders[name](); } catch (e) { toast(e.message || '加载失败', 'err'); }
  }
}

function initNav() {
  $$('.nav-item').forEach((el) => {
    el.addEventListener('click', () => switchView(el.dataset.view));
  });
  $('#logoutBtn').addEventListener('click', async () => {
    try { await api('logout', {}); } catch (e) {}
    location.href = 'login.php';
  });
  window.addEventListener('hashchange', () => {
    const name = (location.hash || '').replace('#', '');
    if (VIEW_META[name] && !$(`.view[data-view="${name}"]`).classList.contains('active')) {
      switchView(name);
    }
  });
}

/* ---------------- 书架 ---------------- */
function bookFormHtml(book = {}) {
  return `
    <label class="field"><span>书名</span><input type="text" id="fTitle" value="${esc(book.title || '')}" placeholder="例如：当斗罗来了个道士"></label>
    <div class="field-row">
      <label class="field"><span>类型</span><input type="text" id="fGenre" value="${esc(book.genre || '')}" placeholder="例如：玄幻"></label>
      <label class="field"><span>创作状态</span><select id="fStatus">
        <option value="writing"${book.status === 'finished' ? '' : ' selected'}>创作中</option>
        <option value="finished"${book.status === 'finished' ? ' selected' : ''}>已完结</option>
      </select></label>
    </div>
    <label class="field"><span>简介</span><textarea id="fIntro" rows="3">${esc(book.intro || '')}</textarea></label>
    <div class="field-row">
      <label class="field"><span>目标字数</span><input type="number" id="fTarget" value="${Number(book.target_words) || 1000000}" min="1000" step="10000"></label>
      <label class="field"><span>已写字数</span><input type="number" id="fWords" value="${Number(book.words) || 0}" min="0" step="1000"></label>
    </div>
    <label class="field"><span>小说大纲</span><textarea id="fOutline" rows="4">${esc(book.outline || '')}</textarea></label>
    <label class="field"><span>世界观设定</span><textarea id="fWorld" rows="4">${esc(book.worldview || '')}</textarea></label>
  `;
}

function openBookModal(book) {
  const isEdit = !!(book && book.id);
  openModal(isEdit ? '编辑作品' : '新建作品', bookFormHtml(book || {}), async () => {
    const title = modalValue('fTitle');
    if (!title) throw new Error('请填写书名');
    const res = await api('book_save', {
      id: isEdit ? book.id : 0,
      title,
      genre: modalValue('fGenre'),
      intro: modalValue('fIntro'),
      status: modalValue('fStatus'),
      target_words: Number(modalValue('fTarget')) || 1000000,
      words: Number(modalValue('fWords')) || 0,
      outline: modalValue('fOutline'),
      worldview: modalValue('fWorld'),
    });
    toast(res.message);
    await loadBooks();
  });
}

function renderShelf(stats) {
  $('#shelfStats').innerHTML = `
    <div class="stat"><b>${stats.total}</b><span>总作品数</span></div>
    <div class="stat"><b>${stats.writing}</b><span>创作中</span></div>
    <div class="stat"><b>${stats.finished}</b><span>已完结</span></div>
    <div class="stat"><b>${esc(stats.words_text)}</b><span>总字数</span></div>
  `;
  const box = $('#bookList');
  if (!state.books.length) {
    box.innerHTML = '<div class="empty">还没有作品，点击右上角「新建作品」或前往拆书导入。</div>';
    return;
  }
  box.innerHTML = state.books.map((b) => {
    const target = Number(b.target_words) || 1;
    const pct = Math.min(100, Math.round((Number(b.words) / target) * 100));
    return `
      <div class="card book">
        <div class="cover" style="${coverStyle(b.title)}">${esc(String(b.title || '书').slice(0, 1))}</div>
        <div class="card-main">
          <div class="card-title">${esc(b.title)}</div>
          <div class="card-meta">
            <span class="tag">${esc(b.genre || '未分类')}</span>
            <span class="tag ${b.status === 'finished' ? 'ok' : ''}">${b.status === 'finished' ? '已完结' : '创作中'}</span>
          </div>
          <p class="card-intro">${esc(b.intro || '暂无简介')}</p>
          <div class="progress"><i style="width:${pct}%"></i></div>
          <div class="card-foot">
            <span>${formatWords(b.words)} / ${formatWords(b.target_words)}　完成 ${pct}%</span>
            <span class="row-btns">
              <button class="btn ghost xs" data-edit="${b.id}">编辑</button>
              <button class="btn danger xs" data-del="${b.id}">删除</button>
            </span>
          </div>
        </div>
      </div>`;
  }).join('');
  $$('#bookList [data-edit]').forEach((btn) => btn.addEventListener('click', () => {
    openBookModal(state.books.find((x) => String(x.id) === btn.dataset.edit));
  }));
  $$('#bookList [data-del]').forEach((btn) => btn.addEventListener('click', () => {
    const book = state.books.find((x) => String(x.id) === btn.dataset.del);
    if (!confirm('确认删除《' + book.title + '》及其关联任务数据？该操作不可恢复。')) return;
    guard(btn, async () => {
      const res = await api('book_delete', { id: book.id });
      toast(res.message);
      await loadBooks();
    }, '删除中…');
  }));
}

async function loadBooks() {
  const res = await api('books');
  state.books = res.books || [];
  renderShelf(res.stats);
  fillBookSelect();
}

loaders.shelf = loadBooks;
/* ---------------- 拆书导入 ---------------- */
const TASK_STATUS = {
  queued: ['排队中', 'wait'],
  running: ['执行中', 'wait'],
  done: ['已完成', 'ok'],
  failed: ['失败', 'err'],
};

function renderTasks(tasks) {
  const box = $('#taskList');
  if (!tasks.length) {
    box.innerHTML = '<div class="empty">暂无后台任务，上传 TXT 后任务会在服务端异步执行。</div>';
    return;
  }
  box.innerHTML = tasks.map((t) => {
    const st = TASK_STATUS[t.status] || [t.status, ''];
    const extra = t.status === 'failed' && t.error ? `<div class="row-err">${esc(t.error)}</div>` : '';
    const scope = t.parse_scope === 'all' ? '整本拆解' : '末 ' + t.chapter_count + ' 章';
    const act = (t.status === 'queued' || t.status === 'failed')
      ? `<button class="btn ghost xs" data-run="${t.id}">立即执行</button>` : '';
    return `
      <div class="row">
        <div class="row-main">
          <div class="row-title">#${t.id} ${esc(t.title || '拆书导入')}</div>
          <div class="row-sub">${t.type === 'split_book' ? '拆书导入' : esc(t.type)}　${scope}　提交于 ${esc(t.created_at)}</div>
          ${extra}
        </div>
        <div class="row-btns"><span class="tag ${st[1]}">${st[0]}</span>${act}</div>
      </div>`;
  }).join('');
  $$('#taskList [data-run]').forEach((btn) => btn.addEventListener('click', () => guard(btn, async () => {
    const res = await api('task_run', { id: Number(btn.dataset.run) });
    toast(res.message);
    await loadTasks();
  }, '执行中…')));
}

async function loadTasks() {
  const res = await api('tasks');
  const tasks = res.tasks || [];
  renderTasks(tasks);
  const pending = tasks.some((t) => t.status === 'queued' || t.status === 'running');
  if (pending && !state.taskTimer) {
    state.taskTimer = setInterval(() => { loadTasks().catch(() => {}); }, 5000);
  }
  if (!pending && state.taskTimer) {
    clearInterval(state.taskTimer);
    state.taskTimer = null;
  }
}

function initSplit() {
  const file = $('#txtFile');
  const zone = $('#dropZone');
  const showFile = () => {
    const f = file.files[0];
    $('#fileInfo').textContent = f ? `${f.name}（${(f.size / 1024 / 1024).toFixed(2)} MB）` : '';
  };
  file.addEventListener('change', showFile);
  ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => {
    e.preventDefault();
    zone.classList.add('over');
  }));
  ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.remove('over')));
  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files.length) { file.files = e.dataTransfer.files; showFile(); }
  });
  $('#parseScope').addEventListener('change', (e) => {
    $('#chapterCountField').classList.toggle('hidden', e.target.value === 'all');
  });
  $('#refreshTasks').addEventListener('click', (e) => guard(e.target, loadTasks, '刷新中…'));
  $('#submitTask').addEventListener('click', (e) => guard(e.target, async () => {
    if (!file.files[0]) throw new Error('请先选择需要拆解的 TXT 文件');
    const fd = new FormData();
    fd.append('file', file.files[0]);
    fd.append('parse_scope', $('#parseScope').value);
    fd.append('chapter_count', $('#chapterCount').value);
    const res = await api('task_create', fd, true);
    toast(res.message);
    file.value = '';
    $('#fileInfo').textContent = '';
    await loadTasks();
  }, '提交中…'));
}

loaders.split = loadTasks;

/* ---------------- 灵感创作器 ---------------- */
function renderChips() {
  $('#genreChips').innerHTML = state.genres.map((g) => `
    <button type="button" class="chip${state.picked.has(g) ? ' active' : ''}" data-genre="${esc(g)}">${esc(g)}</button>
  `).join('');
  $$('#genreChips .chip').forEach((chip) => chip.addEventListener('click', () => {
    const g = chip.dataset.genre;
    if (state.picked.has(g)) state.picked.delete(g); else state.picked.add(g);
    renderChips();
  }));
}

function initIdea() {
  renderChips();
  $('#addGenre').addEventListener('click', () => {
    const val = $('#customGenre').value.trim();
    if (!val) return toast('请输入自定义类型', 'err');
    if (!state.genres.includes(val)) state.genres.push(val);
    state.picked.add(val);
    $('#customGenre').value = '';
    renderChips();
  });
  $('#clearIdea').addEventListener('click', () => {
    ['ideaTitle', 'ideaIntro', 'ideaTheme'].forEach((id) => { $('#' + id).value = ''; });
    state.picked.clear();
    state.genres = ['玄幻', '都市', '历史', '科幻', '武侠', '仙侠', '奇幻', '悬疑'];
    renderChips();
    $('#ideaResult').classList.add('hidden');
    toast('已清空自定义内容');
  });
  $('#genIdea').addEventListener('click', (e) => guard(e.target, async () => {
    if (!state.picked.size) throw new Error('请先选择至少一种小说类型，才能生成书名');
    const res = await api('ai_inspiration', {
      genres: Array.from(state.picked),
      title: $('#ideaTitle').value.trim(),
      intro: $('#ideaIntro').value.trim(),
      theme: $('#ideaTheme').value.trim(),
    });
    const d = res.data || {};
    if (d.title) $('#ideaTitle').value = d.title;
    if (d.intro) $('#ideaIntro').value = d.intro;
    if (d.theme) $('#ideaTheme').value = d.theme;
    const box = $('#ideaResult');
    box.classList.remove('hidden');
    box.innerHTML = `<div class="result-head">AI 生成大纲</div><pre>${esc(d.outline || res.raw || '')}</pre>`;
    toast('灵感生成完成，已回填表单');
  }, '生成中…'));
  $('#saveIdea').addEventListener('click', (e) => guard(e.target, async () => {
    const title = $('#ideaTitle').value.trim();
    if (!title) throw new Error('请先填写或生成书名');
    const outline = $('#ideaResult').querySelector('pre');
    const res = await api('book_save', {
      id: 0,
      title,
      genre: Array.from(state.picked).join('/'),
      intro: $('#ideaIntro').value.trim(),
      status: 'writing',
      target_words: 1000000,
      words: 0,
      outline: outline ? outline.textContent : '',
      worldview: '',
    });
    toast(res.message);
    await loadBooks();
  }, '保存中…'));
}
/* ---------------- 写作台 ---------------- */
function fillBookSelect() {
  const sel = $('#writeBook');
  if (!sel) return;
  const keep = sel.value;
  sel.innerHTML = state.books.map((b) => `<option value="${b.id}">${esc(b.title)}</option>`).join('')
    || '<option value="">暂无作品，请先新建</option>';
  if (keep) sel.value = keep;
}

function fillStyleSelect() {
  ['#writeStyle', '#polishStyle'].forEach((id) => {
    const sel = $(id);
    if (!sel) return;
    const keep = sel.value;
    const opts = state.styles.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    sel.innerHTML = (id === '#writeStyle' ? '<option value="">不指定风格</option>' : '') + opts;
    if (keep) sel.value = keep;
  });
}

function showResult(id, title, content) {
  const box = $(id);
  box.classList.remove('hidden');
  box.innerHTML = `<div class="result-head">${esc(title)}<button class="btn ghost xs" data-copy="1">复制</button></div><pre>${esc(content)}</pre>`;
  box.querySelector('[data-copy]').addEventListener('click', () => {
    navigator.clipboard.writeText(content).then(() => toast('已复制到剪贴板'), () => toast('复制失败，请手动选择', 'err'));
  });
}

async function loadWrite() {
  if (!state.books.length) await loadBooks();
  if (!state.styles.length) await loadStyles();
  fillBookSelect();
  fillStyleSelect();
}

function initWrite() {
  $('#genWrite').addEventListener('click', (e) => guard(e.target, async () => {
    const bookId = Number($('#writeBook').value);
    if (!bookId) throw new Error('请先选择一部作品');
    const req = $('#writeReq').value.trim();
    if (!req) throw new Error('请填写续写需求');
    const res = await api('ai_continue', {
      book_id: bookId,
      requirement: req,
      style_id: Number($('#writeStyle').value) || 0,
      skill_trigger: $('#writeSkill').value.trim(),
    });
    showResult('#writeResult', res.skill ? '续写正文（已应用技能：' + res.skill + '）' : '续写正文', res.content);
    toast('正文生成完成');
  }, '生成中…'));
  $('#genPolish').addEventListener('click', (e) => guard(e.target, async () => {
    const text = $('#polishText').value.trim();
    if (!text) throw new Error('请填写待润色的文本');
    const res = await api('ai_polish', { text, style_id: Number($('#polishStyle').value) || 0 });
    showResult('#polishResult', '润色结果（风格：' + (res.style || '默认') + '）', res.content);
    toast('润色完成');
  }, '润色中…'));
}

loaders.write = loadWrite;

/* ---------------- 写作风格库 ---------------- */
function renderStyles() {
  const tab = state.styleTab;
  const list = state.styles.filter((s) => {
    if (tab === 'preset') return Number(s.is_preset) === 1;
    if (tab === 'mine') return Number(s.is_preset) === 0;
    return true;
  });
  $('#styleCount').textContent = '共 ' + list.length + ' 个';
  const box = $('#styleList');
  if (!list.length) {
    box.innerHTML = '<div class="empty">该分类下暂无风格，点击「+ 新增风格」创建自定义风格。</div>';
    return;
  }
  box.innerHTML = list.map((s) => {
    const preset = Number(s.is_preset) === 1;
    return `
      <div class="card">
        <div class="card-main">
          <div class="card-title">${esc(s.name)}<span class="tag ${preset ? '' : 'ok'}">${preset ? '预设' : '自定义'}</span></div>
          <p class="card-intro">${esc(s.description || '')}</p>
          <div class="card-foot">
            <span class="muted">适用题材：${esc(s.genres || '通用')}</span>
            ${preset ? '' : `<span class="row-btns">
              <button class="btn ghost xs" data-sedit="${s.id}">编辑</button>
              <button class="btn danger xs" data-sdel="${s.id}">删除</button>
            </span>`}
          </div>
        </div>
      </div>`;
  }).join('');
  $$('#styleList [data-sedit]').forEach((btn) => btn.addEventListener('click', () => {
    openStyleModal(state.styles.find((x) => String(x.id) === btn.dataset.sedit));
  }));
  $$('#styleList [data-sdel]').forEach((btn) => btn.addEventListener('click', () => {
    if (!confirm('确认删除该自定义风格？')) return;
    guard(btn, async () => {
      const res = await api('style_delete', { id: Number(btn.dataset.sdel) });
      toast(res.message);
      await loadStyles();
    }, '删除中…');
  }));
}

function openStyleModal(style) {
  const isEdit = !!(style && style.id);
  const s = style || {};
  openModal(isEdit ? '编辑风格' : '新增风格', `
    <label class="field"><span>风格名称</span><input type="text" id="sName" value="${esc(s.name || '')}" maxlength="30" placeholder="例如：爽文"></label>
    <label class="field"><span>风格描述</span><textarea id="sDesc" rows="4" placeholder="节奏明快，主角逆袭打脸">${esc(s.description || '')}</textarea></label>
    <label class="field"><span>适用题材</span><input type="text" id="sGenres" value="${esc(s.genres || '')}" maxlength="100" placeholder="例如：都市/玄幻"></label>
  `, async () => {
    const name = modalValue('sName');
    if (!name) throw new Error('请填写风格名称');
    const res = await api('style_save', {
      id: isEdit ? s.id : 0,
      name,
      description: modalValue('sDesc'),
      genres: modalValue('sGenres'),
    });
    toast(res.message);
    await loadStyles();
  });
}

async function loadStyles() {
  const res = await api('styles');
  state.styles = res.styles || [];
  renderStyles();
  fillStyleSelect();
}

function initStyle() {
  $$('#styleTabs .tab').forEach((tab) => tab.addEventListener('click', () => {
    state.styleTab = tab.dataset.tab;
    $$('#styleTabs .tab').forEach((t) => t.classList.toggle('active', t === tab));
    renderStyles();
  }));
  $('#newStyle').addEventListener('click', () => openStyleModal(null));
}

loaders.style = loadStyles;
/* ---------------- 技能管理 ---------------- */
function renderSkills() {
  const box = $('#skillList');
  if (!state.skills.length) {
    box.innerHTML = '<div class="empty">技能目录为空，请检查 skills 目录与数据库技能表。</div>';
    return;
  }
  box.innerHTML = state.skills.map((s) => {
    const on = Number(s.enabled) === 1;
    const triggers = String(s.triggers || '').split(',').filter(Boolean);
    return `
      <div class="card">
        <div class="card-main">
          <div class="card-title">${esc(s.name)}
            <span class="tag">${esc(s.type)}</span>
            <span class="tag ${on ? 'ok' : 'err'}">${on ? '已启用' : '已停用'}</span>
            ${s.config_exists ? '' : '<span class="tag err">配置缺失</span>'}
          </div>
          <p class="card-intro">${esc(s.description || '')}</p>
          <div class="chips small">${triggers.map((t) => `<span class="chip static" data-trigger="${esc(t)}">${esc(t)}</span>`).join('')}</div>
          <div class="card-foot">
            <span class="muted">配置文件：${esc(s.config_path || '')}</span>
            <span class="row-btns"><button class="btn ghost xs" data-toggle="${s.id}">${on ? '停用' : '启用'}</button></span>
          </div>
        </div>
      </div>`;
  }).join('');
  $$('#skillList [data-toggle]').forEach((btn) => btn.addEventListener('click', () => guard(btn, async () => {
    const res = await api('skill_toggle', { id: Number(btn.dataset.toggle) });
    toast(res.message);
    await loadSkills();
  }, '处理中…')));
  $$('#skillList .chip.static').forEach((chip) => chip.addEventListener('click', () => {
    $('#skillTrigger').value = chip.dataset.trigger;
    toast('已填入触发词：' + chip.dataset.trigger);
  }));
}

async function loadSkills() {
  const res = await api('skills');
  state.skills = res.skills || [];
  renderSkills();
}

function initSkill() {
  $('#runSkill').addEventListener('click', (e) => guard(e.target, async () => {
    const trigger = $('#skillTrigger').value.trim();
    if (!trigger) throw new Error('请填写技能触发词，例如 /story-deslop');
    const res = await api('ai_skill', { trigger, text: $('#skillText').value.trim() });
    showResult('#skillResult', '技能执行结果（' + res.skill + '）', res.content);
    toast('技能执行完成');
  }, '执行中…'));
}

loaders.skill = loadSkills;

/* ---------------- 提示词模板 ---------------- */
function renderPrompts() {
  const list = state.prompts.filter((p) => (state.promptTab === 'mine' ? Number(p.user_id) !== 0 : true));
  const box = $('#promptList');
  if (!list.length) {
    box.innerHTML = '<div class="empty">没有匹配的模板，点击「+ 新建模板」创建属于你的提示词。</div>';
    return;
  }
  box.innerHTML = list.map((p) => {
    const preset = Number(p.user_id) === 0;
    const vars = String(p.variables || '').split(',').filter(Boolean);
    return `
      <div class="card">
        <div class="card-main">
          <div class="card-title">${esc(p.name)}
            <span class="tag">${esc(p.type)}</span>
            <span class="tag ${preset ? '' : 'ok'}">${preset ? '系统预设' : '我的创作'}</span>
          </div>
          <pre class="card-code">${esc(p.content)}</pre>
          <div class="chips small">${vars.map((v) => `<span class="chip static">${esc(v)}</span>`).join('')}</div>
          <div class="card-foot">
            <span class="row-btns">
              <button class="btn ghost xs" data-prun="${p.id}">运行</button>
              ${preset ? '' : `<button class="btn ghost xs" data-pedit="${p.id}">编辑</button>
              <button class="btn danger xs" data-pdel="${p.id}">删除</button>`}
            </span>
          </div>
        </div>
      </div>`;
  }).join('');
  $$('#promptList [data-pedit]').forEach((btn) => btn.addEventListener('click', () => {
    openPromptModal(state.prompts.find((x) => String(x.id) === btn.dataset.pedit));
  }));
  $$('#promptList [data-pdel]').forEach((btn) => btn.addEventListener('click', () => {
    if (!confirm('确认删除该模板？')) return;
    guard(btn, async () => {
      const res = await api('prompt_delete', { id: Number(btn.dataset.pdel) });
      toast(res.message);
      await loadPrompts();
    }, '删除中…');
  }));
  $$('#promptList [data-prun]').forEach((btn) => btn.addEventListener('click', () => {
    openPromptRunModal(state.prompts.find((x) => String(x.id) === btn.dataset.prun));
  }));
}

function openPromptModal(prompt) {
  const isEdit = !!(prompt && prompt.id);
  const p = prompt || {};
  openModal(isEdit ? '编辑模板' : '新建模板', `
    <div class="field-row">
      <label class="field"><span>模板名称</span><input type="text" id="pName" value="${esc(p.name || '')}" maxlength="60"></label>
      <label class="field"><span>模板类型</span><input type="text" id="pType" value="${esc(p.type || 'general')}" maxlength="20"></label>
    </div>
    <label class="field"><span>模板内容（变量用花括号包裹，如 {title}、{genre}、{theme}）</span>
      <textarea id="pContent" rows="8">${esc(p.content || '')}</textarea></label>
  `, async () => {
    const res = await api('prompt_save', {
      id: isEdit ? p.id : 0,
      name: modalValue('pName'),
      type: modalValue('pType') || 'general',
      content: modalValue('pContent'),
    });
    toast(res.message);
    await loadPrompts();
  });
}

function openPromptRunModal(prompt) {
  const bookOpts = state.books.map((b) => `<option value="${b.id}">${esc(b.title)}</option>`).join('');
  openModal('运行模板：' + prompt.name, `
    <label class="field"><span>关联作品（可选，用于自动填充变量）</span>
      <select id="rBook"><option value="">不关联作品</option>${bookOpts}</select></label>
    <div class="field-row">
      <label class="field"><span>{title}</span><input type="text" id="rTitle"></label>
      <label class="field"><span>{genre}</span><input type="text" id="rGenre"></label>
    </div>
    <div class="field-row">
      <label class="field"><span>{theme}</span><input type="text" id="rTheme"></label>
      <label class="field"><span>{time_period}</span><input type="text" id="rTime"></label>
    </div>
    <div class="field-row">
      <label class="field"><span>{location}</span><input type="text" id="rLoc"></label>
      <label class="field"><span>{description}</span><input type="text" id="rDesc"></label>
    </div>
    <div class="result" id="rOut"><pre>${esc(prompt.content)}</pre></div>
  `, async () => {
    const vars = {};
    const map = { title: 'rTitle', genre: 'rGenre', theme: 'rTheme', time_period: 'rTime', location: 'rLoc', description: 'rDesc' };
    Object.keys(map).forEach((k) => { const v = modalValue(map[k]); if (v) vars[k] = v; });
    const res = await api('ai_prompt_run', { id: prompt.id, book_id: Number(modalValue('rBook')) || 0, vars });
    showResult('#promptRunResult', '模板执行结果：' + prompt.name, res.content);
    toast('模板执行完成');
  }, '执行');
}

async function loadPrompts(keyword = '') {
  const res = await api('prompts' + (keyword ? '&q=' + encodeURIComponent(keyword) : ''));
  state.prompts = res.prompts || [];
  renderPrompts();
}

function initPrompt() {
  $$('#promptTabs .tab').forEach((tab) => tab.addEventListener('click', () => {
    state.promptTab = tab.dataset.tab;
    $$('#promptTabs .tab').forEach((t) => t.classList.toggle('active', t === tab));
    renderPrompts();
  }));
  let timer = null;
  $('#promptSearch').addEventListener('input', (e) => {
    clearTimeout(timer);
    const kw = e.target.value.trim();
    timer = setTimeout(() => { loadPrompts(kw).catch((err) => toast(err.message, 'err')); }, 300);
  });
  $('#newPrompt').addEventListener('click', () => openPromptModal(null));
}

loaders.prompt = () => loadPrompts($('#promptSearch').value.trim());
const MCP_TYPES = { http: 'http', streamable_http: 'streamable_http', sse: 'sse' };

function renderMcpStats(s) {
  const stats = s || {};
  $('#mcpStats').innerHTML = [
    ['总调用次数', stats.total || 0],
    ['成功次数', stats.success || 0],
    ['失败次数', stats.fail || 0],
    ['成功率', (stats.rate || 0) + '%'],
    ['平均耗时', (stats.avg_ms || 0) + ' ms'],
  ].map(([k, v]) => `<div class="stat"><span>${k}</span><b>${esc(String(v))}</b></div>`).join('');
}

function renderMcps() {
  const list = state.mcps;
  if (!list.length) {
    $('#mcpList').innerHTML = '<div class="empty">还没有接入 MCP 插件，点击右上角添加，为创作助手扩展更多工具。</div>';
    return;
  }
  $('#mcpList').innerHTML = list.map((m) => `
    <div class="card">
      <div class="card-head">
        <b>${esc(m.name)}</b>
        <span class="tag">${esc(m.type)}</span>
      </div>
      <pre class="card-code">${esc(m.url)}</pre>
      <div class="card-meta">最后调用：${esc(m.last_called_at || '尚未调用')}</div>
      <div class="card-foot">
        <button class="btn ghost xs" data-test="${m.id}">调用测试</button>
        <button class="btn ghost xs" data-edit="${m.id}">编辑</button>
        <button class="btn ghost xs danger" data-del="${m.id}">删除</button>
      </div>
    </div>`).join('');
  $$('#mcpList [data-test]').forEach((btn) => btn.addEventListener('click', (e) => guard(e.target, async () => {
    const res = await api('mcp_call', { id: Number(btn.dataset.test) });
    toast(res.message, res.success ? 'ok' : 'err');
    await loadMcps();
  }, '测试中…')));
  $$('#mcpList [data-edit]').forEach((btn) => btn.addEventListener('click', () => {
    openMcpModal(state.mcps.find((m) => String(m.id) === btn.dataset.edit));
  }));
  $$('#mcpList [data-del]').forEach((btn) => btn.addEventListener('click', async () => {
    const m = state.mcps.find((x) => String(x.id) === btn.dataset.del);
    if (!confirm(`确认删除插件「${m.name}」？`)) return;
    try {
      const res = await api('mcp_delete', { id: m.id });
      toast(res.message);
      await loadMcps();
    } catch (err) { toast(err.message, 'err'); }
  }));
}

function openMcpModal(mcp) {
  const m = mcp || {};
  const isEdit = !!m.id;
  const opts = Object.keys(MCP_TYPES).map((t) => `<option value="${t}"${m.type === t ? ' selected' : ''}>${t}</option>`).join('');
  openModal(isEdit ? '编辑插件' : '添加 MCP 插件', `
    <label class="field"><span>插件名称</span><input type="text" id="cName" maxlength="60" value="${esc(m.name || '')}" placeholder="如 参考资料搜索"></label>
    <label class="field"><span>插件地址</span><input type="text" id="cUrl" value="${esc(m.url || '')}" placeholder="https://example.com/mcp"></label>
    <label class="field"><span>插件类型</span><select id="cType">${opts}</select></label>`, async () => {
    const name = modalValue('cName');
    if (!name) throw new Error('请填写插件名称');
    const res = await api('mcp_save', { id: isEdit ? m.id : 0, name, url: modalValue('cUrl'), type: modalValue('cType') });
    toast(res.message);
    await loadMcps();
  });
}

async function loadMcps() {
  const res = await api('mcp');
  state.mcps = res.plugins || [];
  renderMcpStats(res.stats);
  renderMcps();
}

function initMcp() {
  $('#newMcp').addEventListener('click', () => openMcpModal(null));
}
loaders.mcp = loadMcps;

async function loadModel() {
  const res = await api('model_get');
  const c = res.config || {};
  if ($('#mInterface')) {
    const validProviders = ['zhipu','official','custom'];
    const prov = validProviders.includes(c.provider) ? c.provider : 'custom';
    $('#mInterface').value = prov;
}
if ($('#mBase')) $('#mBase').value = c.api_base || '';
if ($('#mKey')) {
    // 已在后端对 key 做掩码，直接填入即可（占位字符已在 placeholder 中处理）
    $('#mKey').value = c.api_key || '';
}
if ($('#mModel')) $('#mModel').value = c.model || '';
if ($('#mTemp')) $('#mTemp').value = c.temperature != null ? c.temperature : '0.7';
if ($('#mTokens')) $('#mTokens').value = c.max_tokens != null ? c.max_tokens : '129000';
if ($('#mSystem')) $('#mSystem').value = c.system_prompt || '';
if ($('#mKey')) $('#mKey').placeholder = c.has_key ? '已保存密钥，留空或不修改即保持原值' : 'sk-...';
}

function initSetting() {
  // 切换接口类型时更新表单显示和只读属性
  const mInterfaceEl = $('#mInterface');
if (mInterfaceEl) {
  mInterfaceEl.addEventListener('change', () => {
    const type = mInterfaceEl.value;
    const rowBaseEl = $('#rowBase');
    const mBaseEl = $('#mBase');
    const mKeyEl = $('#mKey');
    const mModelEl = $('#mModel');
    if (type === 'zhipu') {
      if (rowBaseEl) rowBaseEl.classList.add('hidden');
      if (mBaseEl) mBaseEl.value = 'https://open.bigmodel.cn/api/paas/v4';
      if (mKeyEl) mKeyEl.removeAttribute('readonly');
      if (mModelEl) mModelEl.removeAttribute('readonly');
    } else if (type === 'official') {
      if (rowBaseEl) rowBaseEl.classList.add('hidden');
      if (mBaseEl) mBaseEl.value = 'https://ai.anyyds.cn/v1';
      if (mKeyEl) { mKeyEl.value = '**************...'; mKeyEl.setAttribute('readonly', true); }
      if (mModelEl) { mModelEl.value = 'GLM-4.5-Flash'; mModelEl.setAttribute('readonly', true); }
    } else {
      if (rowBaseEl) rowBaseEl.classList.remove('hidden');
      if (mBaseEl) mBaseEl.value = '';
      if (mKeyEl) { mKeyEl.value = ''; mKeyEl.removeAttribute('readonly'); }
      if (mModelEl) { mModelEl.value = ''; mModelEl.removeAttribute('readonly'); }
    }
  });
}

  $('#fetchModels').addEventListener('click', (e) => guard(e.target, async () => {
    const res = await api('model_info');
    const models = res.models || [];
    if (!models.length) throw new Error('接口未返回模型列表，可手动输入模型名称');
    $('#modelOptions').innerHTML = models.map((m) => `<option value="${esc(m)}"></option>`).join('');
    toast('已拉取 ' + models.length + ' 个模型，点击输入框查看');
  }, '拉取中…'));

  $('#saveModel').addEventListener('click', (e) => guard(e.target, async () => {
    const res = await api('model_save', {
      provider: (function(){ const el = $('#mInterface'); return el ? el.value.trim() : ''; })(),
      api_base: $('#mBase').value.trim(),
      api_key: $('#mKey').value.trim(),
      model: $('#mModel').value.trim(),
      temperature: $('#mTemp').value.trim(),
      max_tokens: $('#mTokens').value.trim(),
      system_prompt: $('#mSystem').value.trim(),
    });
    toast(res.message);
    await loadModel();
  }, '保存中…'));
}
loaders.setting = loadModel;

function renderProfile(p) {
  state.profile = p;
  $('#profileCard').innerHTML = `
    <div class="profile-head">
      <div class="avatar" style="${coverStyle(p.username)}">${esc(p.username.charAt(0))}</div>
      <div>
        <b>${esc(p.username)}</b>
        <span class="lead">用户 ID ${p.id} · Lv.${p.level} · 注册于 ${esc(p.created_at)}</span>
      </div>
    </div>
    <div class="stats">
      <div class="stat"><span>总作品数</span><b>${p.books}</b></div>
      <div class="stat"><span>总字数</span><b>${esc(p.words_text)}</b></div>
      <div class="stat"><span>总积分</span><b>${p.points}</b></div>
      <div class="stat"><span>等级</span><b>Lv.${p.level}</b></div>
    </div>`;
  const link = location.origin + location.pathname.replace(/index\.php$/, '') + 'login.php?invite=' + encodeURIComponent(p.invite_code);
  $('#inviteBox').innerHTML = `
    <div class="invite-row"><span>邀请码</span><b>${esc(p.invite_code)}</b></div>
    <div class="invite-row"><span>邀请进度</span><b>已邀 ${p.invited_count}/${p.invite_limit} 人</b></div>
    <label class="field"><span>邀请链接</span><input type="text" id="inviteLink" readonly value="${esc(link)}"></label>
    <button class="btn ghost" id="copyInvite">复制邀请链接</button>`;
  $('#copyInvite').addEventListener('click', () => {
    navigator.clipboard.writeText(link).then(() => toast('邀请链接已复制'), () => toast('复制失败，请手动选择', 'err'));
  });
  const btn = $('#checkinBtn');
  btn.disabled = !!p.checked_in;
  btn.textContent = p.checked_in ? '今日已签到' : '每日领取';
}

function renderLogs(logs) {
  if (!logs.length) {
    $('#logList').innerHTML = '<div class="empty">暂无登录记录。</div>';
    return;
  }
  $('#logList').innerHTML = logs.map((l) => `
    <div class="row">
      <div class="row-main"><b>${esc(l.ip || '未知 IP')}</b><span>${esc(l.device || '未知设备')}</span></div>
      <span class="row-time">${esc(l.created_at)}</span>
    </div>`).join('');
}

async function loadMine() {
  const [p, logs] = await Promise.all([api('profile'), api('login_logs')]);
  renderProfile(p.user);
  renderLogs(logs.logs || []);
}

function initMine() {
  $('#checkinBtn').addEventListener('click', (e) => guard(e.target, async () => {
    const res = await api('checkin', {});
    toast(res.message);
    renderProfile(res.user);
  }, '领取中…'));
  $('#changePwd').addEventListener('click', (e) => guard(e.target, async () => {
    const oldPwd = $('#oldPwd').value;
    const newPwd = $('#newPwd').value;
    if (!oldPwd || !newPwd) throw new Error('请填写原密码与新密码');
    const res = await api('change_password', { old_password: oldPwd, new_password: newPwd });
    toast(res.message);
    setTimeout(() => { location.href = 'login.php'; }, 1200);
  }, '提交中…'));
  $('#checkUpdate').addEventListener('click', (e) => guard(e.target, async () => {
    const res = await api('check_update');
    toast(res.message + '（当前 ' + res.version + '）');
  }, '检查中…'));
}
loaders.mine = loadMine;

function init() {
  initTheme();
  initModal();
  initNav();
  initSplit();
  initIdea();
  initWrite();
  initStyle();
  initSkill();
  initPrompt();
  initMcp();
  initSetting();
  initMine();
  // 确保在加载模型配置时触发接口类型的默认状态
  if ($('#mInterface')) $('#mInterface').dispatchEvent(new Event('change'));
  renderChips();
  const hash = (location.hash || '').replace('#', '');
  switchView(VIEW_META[hash] ? hash : 'shelf');
}

init();
