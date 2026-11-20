(function () {
  const storageKey = 'theme';
  const themes = ['light', 'dark', 'auto'];
  const themeLabels = {
    light: '浅色',
    dark: '深色',
    auto: '自动',
  };
  function getPreferredTheme() {
    const storedTheme = localStorage.getItem(storageKey);
    return themes.includes(storedTheme) ? storedTheme : 'auto';
  }

  function resolveTheme(theme) {
    if (theme !== 'auto') return theme;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(theme) {
    const resolved = resolveTheme(theme);
    document.documentElement.dataset.themePreference = theme;
    document.documentElement.dataset.bsTheme = resolved;
    document.documentElement.dataset.mdbTheme = resolved;
  }

  function readThemeValue(button) {
    return button.dataset.bsThemeValue || button.dataset.theme || '';
  }

  applyTheme(getPreferredTheme());

  document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('navbarDropdownToggleTheme');
    if (!toggle) return;

    const menu =
      document.getElementById('themeMenu') ||
      document.querySelector(`[aria-labelledby="navbarDropdownToggleTheme"]`);
    if (!menu) return;

    if (!menu.id) menu.id = 'themeMenu';
    toggle.setAttribute('aria-controls', 'themeMenu');

    const buttons = [...menu.querySelectorAll('.menu-item[data-bs-theme-value], .menu-item[data-theme]')];

    function showActiveTheme(theme) {
      if (!themes.includes(theme)) return;

      toggle.setAttribute('aria-label', `切换主题（${themeLabels[theme]}）`);

      buttons.forEach((button) => {
        const value = readThemeValue(button);
        const isActive = value === theme;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', String(isActive));
        const check = button.querySelector('.theme-check, .fa-check');
        if (check) {
          check.classList.toggle('d-none', !isActive);
          check.toggleAttribute('hidden', !isActive);
        }
      });
    }

    showActiveTheme(getPreferredTheme());

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const theme = readThemeValue(button);
        if (!themes.includes(theme)) return;
        localStorage.setItem(storageKey, theme);
        applyTheme(theme);
        showActiveTheme(theme);
      });
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getPreferredTheme() === 'auto') applyTheme('auto');
    });
  });
})();
