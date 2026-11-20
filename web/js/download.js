const form = document.getElementById('downloadForm');
const logEl = document.getElementById('downloadLog');
const startBtn = document.getElementById('startBtn');
const fetchMetaBtn = document.getElementById('fetchMetaBtn');
const statusEl = document.getElementById('downloadStatus');

function setStatus(message, state) {
  if (!statusEl) return;
  statusEl.textContent = message;
  statusEl.dataset.state = state;
}

for (const key of ['id', 'cookie', 'proxyPort', 'character']) {
  const el = document.getElementById(key);
  const saved = localStorage.getItem('pixiv-anim-' + key);
  if (el && saved) el.value = saved;
  el?.addEventListener('change', () => localStorage.setItem('pixiv-anim-' + key, el.value));
}

function appendLog(message, cls = '') {
  if (logEl.dataset.empty !== '0') {
    logEl.textContent = '';
    logEl.dataset.empty = '0';
  }
  const line = document.createElement('div');
  line.className = 'download-log-line' + (cls ? ' ' + cls : '');
  if (cls === 'download-log-ok' || cls === 'download-log-err') {
    const marker = document.createElement('span');
    marker.className = 'download-log-marker';
    marker.setAttribute('aria-hidden', 'true');
    marker.textContent = cls === 'download-log-ok' ? '✓' : '!';
    line.appendChild(marker);
  }
  const text = document.createElement('span');
  text.textContent = message;
  line.appendChild(text);
  logEl.appendChild(line);
  logEl.scrollTop = logEl.scrollHeight;
}

function clearLog() {
  logEl.textContent = '';
  logEl.dataset.empty = '0';
}

function cookieHasSession(cookie) {
  return /(?:^|;\s*)PHPSESSID=/i.test(cookie);
}

function warnIfCookieIncomplete(cookie) {
  if (!cookie || cookieHasSession(cookie)) return;
  appendLog('提示：这段 Cookie 不含 PHPSESSID（HttpOnly，document.cookie 读不到），受限作品会返回 404。请从 DevTools → Network → 任意请求的 Cookie 请求头复制完整值。');
}

function headers() {
  const h = { 'Content-Type': 'application/json' };
  const cookie = document.getElementById('cookie').value.trim();
  const proxyPort = document.getElementById('proxyPort').value.trim();
  if (cookie) h['X-Pixiv-Cookie'] = cookie;
  if (proxyPort) h['X-Proxy-Port'] = proxyPort;
  return h;
}

async function api(path, options = {}) {
  const res = await fetch(path, options);
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.success === false) {
    throw new Error(data.error || `请求失败 HTTP ${res.status}`);
  }
  return data.data ?? data;
}

fetchMetaBtn?.addEventListener('click', async () => {
  const id = document.getElementById('id').value.trim();
  const cookie = document.getElementById('cookie').value.trim();
  if (!id) {
    appendLog('请先填写作品 ID', 'download-log-err');
    setStatus('缺少作品 ID', 'error');
    return;
  }
  clearLog();
  warnIfCookieIncomplete(cookie);
  setStatus('正在查询', 'running');
  appendLog('正在请求 /api/illust/' + id + ' …');
  try {
    const illust = await api('/api/illust/' + encodeURIComponent(id), { headers: headers() });
    const body = illust.body || {};
    const type = body.illustType;
    appendLog(`illustType = ${type}（0 图片 / 1 漫画 / 2 动图）`, 'download-log-ok');
    appendLog(`title = ${body.title || ''}`);
    appendLog(`pageCount = ${body.pageCount ?? '-'}`);
    if (type === 2) {
      appendLog('正在请求 /api/ugoira_meta/' + id + ' …');
      const meta = await api('/api/ugoira_meta/' + encodeURIComponent(id), { headers: headers() });
      appendLog('originalSrc = ' + (meta.body?.originalSrc || ''), 'download-log-ok');
      appendLog('frames = ' + (meta.body?.frames?.length ?? 0));
    } else if (body.urls?.original) {
      appendLog('original = ' + body.urls.original, 'download-log-ok');
    }
    setStatus('查询完成', 'success');
  } catch (e) {
    appendLog(String(e.message || e), 'download-log-err');
    setStatus('查询失败', 'error');
  }
});

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const id = document.getElementById('id').value.trim();
  const cookie = document.getElementById('cookie').value.trim();
  const proxyPort = Number(document.getElementById('proxyPort').value || 0);
  const character = document.getElementById('character').value.trim();
  const redownload = document.getElementById('redownload').checked;

  if (!id || !cookie) {
    appendLog('请填写作品 ID 与 Cookie', 'download-log-err');
    return;
  }
  if (redownload && !confirm('将清空该作品目录并重新下载，确定继续？')) {
    return;
  }

  clearLog();
  warnIfCookieIncomplete(cookie);
  startBtn.disabled = true;
  setStatus('正在下载', 'running');
  appendLog('开始下载…');

  try {
    const result = await api('/api/download', {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ id, cookie, proxyPort, character, redownload }),
    });

    for (const item of result.logs || []) {
      if (item.type === 'error') {
        appendLog(item.message || item.error || JSON.stringify(item), 'download-log-err');
      } else if (item.type === 'success') {
        appendLog(item.message || `成功: ${item.filename || ''}`, 'download-log-ok');
      } else if (item.type === 'skipped') {
        appendLog(`跳过已存在: ${item.filename}`);
      } else {
        appendLog(item.message || item.filename || item.type || JSON.stringify(item));
      }
    }

    appendLog(
      `完成：illustType=${result.illustType}，成功 ${result.successCount}，失败 ${result.failCount ?? 0}，文件 ${result.fileCount}`,
      'download-log-ok',
    );
    setStatus(result.failCount ? '部分失败' : '下载完成', result.failCount ? 'error' : 'success');

    if (result.viewUrl) {
      const a = document.createElement('a');
      a.className = 'btn btn-link px-0';
      a.href = result.viewUrl;
      a.textContent = '查看作品 →';
      a.style.color = 'var(--gallery-accent)';
      logEl.appendChild(a);
    }
  } catch (e) {
    appendLog(String(e.message || e), 'download-log-err');
    setStatus('下载失败', 'error');
  } finally {
    startBtn.disabled = false;
  }
});
