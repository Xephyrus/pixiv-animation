const id = new URLSearchParams(location.search).get('id')
    || location.pathname.split('/').filter(Boolean).pop();

const titleEl = document.getElementById('title');
const metaEl = document.getElementById('meta');
const grid = document.getElementById('grid');
const editLink = document.getElementById('editLink');

// 与 F:\yibase\download-widget 一致的灯箱能力：缩放、1:1、旋转、全屏、缩略图、下载
const lightboxOptions = {
  Hash: true,
  Carousel: {
    preload: 2,
    Thumbs: { type: 'classic' },
    Toolbar: {
      display: {
        left: ['counter'],
        middle: [],
        right: [
          'zoomIn',
          'zoomOut',
          'toggle1to1',
          'rotateCCW',
          'rotateCW',
          'fullscreen',
          'thumbs',
          'download',
          'close',
        ],
      },
    },
    breakpoints: {
      '(max-width: 719px)': {
        Arrows: false,
        Toolbar: {
          display: {
            left: ['counter'],
            middle: [],
            right: ['thumbs', 'download', 'close'],
          },
        },
      },
    },
  },
};

function bindLightbox() {
  if (window.Fancybox) {
    window.Fancybox.bind('[data-fancybox="gallery"]', lightboxOptions);
    return;
  }

  // CDN 未就绪时重试；始终失败则退化为新标签页打开原图
  let attempts = 0;
  const timer = setInterval(() => {
    attempts += 1;
    if (window.Fancybox) {
      clearInterval(timer);
      window.Fancybox.bind('[data-fancybox="gallery"]', lightboxOptions);
      return;
    }
    if (attempts > 50) {
      clearInterval(timer);
      for (const link of grid.querySelectorAll('a[data-fancybox]')) {
        link.target = '_blank';
        link.rel = 'noopener';
      }
    }
  }, 100);
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

try {
  const res = await fetch('/api/works/' + encodeURIComponent(id));
  const json = await res.json();
  if (!res.ok || json.success === false) throw new Error(json.error || '加载失败');

  const work = json.data;
  const files = work.files || [];
  titleEl.textContent = work.title || work.name || work.id;
  metaEl.textContent = `ID ${work.id} · ${work.illustType === 1 ? '漫画' : '图片'} · ${work.fileCount} 张 · 点击查看大图`;
  if (editLink) {
    editLink.href = '/edit/' + encodeURIComponent(work.id);
    editLink.hidden = false;
  }

  grid.innerHTML = files.map((file, index) => {
    const src = `/downloads/${encodeURIComponent(work.id)}/${encodeURIComponent(file)}`;
    const safeName = escapeHtml(file);
    return `<a href="${src}" data-fancybox="gallery" data-caption="${safeName}" aria-label="查看大图 ${safeName}">
        <img src="${src}" alt="${safeName}" loading="${index < 8 ? 'eager' : 'lazy'}" decoding="async">
    </a>`;
  }).join('');

  bindLightbox();
} catch (error) {
  titleEl.textContent = '无法加载作品';
  metaEl.textContent = String(error.message || error);
}
