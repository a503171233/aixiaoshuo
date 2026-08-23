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
/* APPEND_MARK */
