const grid = document.getElementById('grid');

// 与 download-widget 完全一致的 Fancybox 4 配置
const lightboxOptions = {
  Hash: true,
  Carousel: {
    preload: 2,
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
          'autoplay',
          'fullscreen',
          'thumbs',
          'download',
          'close',
        ],
      },
    },
    Thumbs: { type: 'classic' },
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
  const links = grid ? grid.querySelectorAll('a[data-fancybox]') : [];

  if (window.Fancybox) {
    if (typeof window.Fancybox.unbind === 'function') {
      window.Fancybox.unbind('[data-fancybox="gallery"]');
    }
    window.Fancybox.bind('[data-fancybox="gallery"]', lightboxOptions);
    // 灯箱接管后不再需要新标签页兜底
    for (const link of links) {
      link.removeAttribute('target');
      link.removeAttribute('rel');
    }
    return;
  }

  // CDN 未就绪时重试；始终失败则保留 target=_blank 直接打开原图
  let attempts = 0;
  const timer = setInterval(() => {
    attempts += 1;
    if (window.Fancybox) {
      clearInterval(timer);
      if (typeof window.Fancybox.unbind === 'function') {
        window.Fancybox.unbind('[data-fancybox="gallery"]');
      }
      window.Fancybox.bind('[data-fancybox="gallery"]', lightboxOptions);
      for (const link of links) {
        link.removeAttribute('target');
        link.removeAttribute('rel');
      }
      return;
    }
    if (attempts > 50) {
      clearInterval(timer);
    }
  }, 100);
}

bindLightbox();
