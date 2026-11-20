const form = document.getElementById('downloadForm');
const logEl = document.getElementById('downloadLog');
const startBtn = document.getElementById('startBtn');
const fetchMetaBtn = document.getElementById('fetchMetaBtn');
const statusEl = document.getElementById('downloadStatus');
const progressEl = document.getElementById('downloadProgress');
const progressBar = document.getElementById('downloadProgressBar');
const progressWrap = document.getElementById('downloadProgressBarWrap');
const progressText = document.getElementById('downloadProgressText');

function setStatus(message, state) {
  if (!statusEl) return;
  statusEl.textContent = message;
  statusEl.dataset.state = state;
}

// 人物名称属于单个作品的信息，不做本地缓存填充，避免串到其它作品
for (const key of ['id', 'cookie', 'proxyPort']) {
  const el = document.getElementById(key);
  const saved = localStorage.getItem('pixiv-anim-' + key);
  if (el && saved) el.value = saved;
  el?.addEventListener('change', () => localStorage.setItem('pixiv-anim-' + key, el.value));
}
localStorage.removeItem('pixiv-anim-character');

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

function formatSize(bytes) {
  const value = Number(bytes) || 0;
  if (value <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let index = 0;
  let size = value;
  while (size >= 1024 && index < units.length - 1) {
    size /= 1024;
    index += 1;
  }
  return (size >= 10 || index === 0 ? size.toFixed(0) : size.toFixed(1)) + ' ' + units[index];
}

function setProgress(percent, text) {
  if (!progressEl) return;
  progressEl.hidden = false;
  const value = Math.max(0, Math.min(100, Number(percent) || 0));
  if (progressBar) progressBar.style.width = value.toFixed(1) + '%';
  if (progressWrap) progressWrap.setAttribute('aria-valuenow', String(Math.round(value)));
  if (progressText && text !== undefined) progressText.textContent = text;
}

function resetProgress() {
  if (!progressEl) return;
  progressEl.hidden = true;
  if (progressBar) progressBar.style.width = '0%';
  if (progressWrap) progressWrap.setAttribute('aria-valuenow', '0');
  if (progressText) progressText.textContent = '';
}

function handleDownloadEvent(event) {
  switch (event.type) {
    case 'start':
      setProgress(0, `准备下载 ${event.total} 个文件`);
      appendLog(`开始下载：${event.title || event.id}（${event.total} 个文件）`);
      break;
    case 'file':
      if (event.status === 'start') {
        appendLog(`↓ ${event.filename}（${event.index}/${event.total}）`);
      } else if (event.status === 'skipped') {
        appendLog(`跳过已存在：${event.filename}`);
      } else if (event.status === 'success') {
        appendLog(`✓ ${event.filename} · ${formatSize(event.size)}`, 'download-log-ok');
      } else if (event.status === 'error') {
        appendLog(`! ${event.filename}：${event.error}`, 'download-log-err');
      }
      break;
    case 'progress': {
      const fileRatio = event.totalBytes > 0 ? Math.min(1, event.bytes / event.totalBytes) : 0;
      const percent = event.total > 0 ? ((event.index - 1 + fileRatio) / event.total) * 100 : 0;
      setProgress(
        percent,
        `下载中 ${event.index}/${event.total} · ${event.filename} · ${formatSize(event.bytes)}`
      );
      break;
    }
    case 'info':
      if (event.message) appendLog(event.message);
      break;
    case 'error':
      appendLog(event.message || '下载失败', 'download-log-err');
      break;
    default:
      break;
  }
}

async function readDownloadStream(response, onEvent) {
  if (!response.body) {
    const text = await response.text();
    for (const chunk of text.split('\n\n')) {
      const line = chunk.split('\n').find((item) => item.startsWith('data:'));
      if (line) onEvent(JSON.parse(line.slice(5).trim()));
    }
    return;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    let separator;
    while ((separator = buffer.indexOf('\n\n')) >= 0) {
      const chunk = buffer.slice(0, separator);
      buffer = buffer.slice(separator + 2);
      for (const line of chunk.split('\n')) {
        if (!line.startsWith('data:')) continue;
        const payload = line.slice(5).trim();
        if (!payload) continue;
        try {
          onEvent(JSON.parse(payload));
        } catch {
          // 忽略不完整的事件
        }
      }
    }
  }
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
  resetProgress();
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
  resetProgress();
  warnIfCookieIncomplete(cookie);
  startBtn.disabled = true;
  setStatus('正在下载', 'running');
  appendLog('开始下载…');

  try {
    const response = await fetch('/api/download', {
      method: 'POST',
      headers: { ...headers(), Accept: 'text/event-stream' },
      body: JSON.stringify({ id, cookie, proxyPort, character, redownload }),
    });

    const contentType = response.headers.get('content-type') || '';
    if (!response.ok || !contentType.includes('text/event-stream')) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `请求失败 HTTP ${response.status}`);
    }

    let result = null;
    let streamError = null;

    await readDownloadStream(response, (evt) => {
      if (evt.type === 'done') {
        result = evt.result || null;
        return;
      }
      if (evt.type === 'error') {
        streamError = evt.message || '下载失败';
        return;
      }
      handleDownloadEvent(evt);
    });

    if (streamError) throw new Error(streamError);
    if (!result) throw new Error('下载中断，未收到完成事件');

    const failCount = result.failCount ?? 0;
    setProgress(100, `完成：${result.fileCount} 个文件，失败 ${failCount}`);
    appendLog(
      `完成：illustType=${result.illustType}，成功 ${result.successCount}，失败 ${failCount}，文件 ${result.fileCount}`,
      failCount ? 'download-log-err' : 'download-log-ok',
    );
    setStatus(failCount ? '部分失败' : '下载完成', failCount ? 'error' : 'success');

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
