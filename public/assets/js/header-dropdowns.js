(() => {
  'use strict';
  const menus = [...document.querySelectorAll('[data-header-menu]')];
  if (!menus.length) return;
  const close = menu => {
    menu.querySelector('[data-header-panel]')?.setAttribute('hidden','');
    menu.querySelector('[data-header-trigger]')?.setAttribute('aria-expanded','false');
  };
  const closeAll = except => menus.forEach(menu => { if (menu !== except) close(menu); });
  menus.forEach(menu => {
    const button = menu.querySelector('[data-header-trigger]');
    const panel = menu.querySelector('[data-header-panel]');
    if (!button || !panel) return;
    button.addEventListener('click', () => {
      const willOpen = panel.hidden;
      closeAll(menu);
      panel.hidden = !willOpen;
      button.setAttribute('aria-expanded', willOpen ? 'true':'false');
      if (willOpen) {
        const header = document.querySelector('.header-main');
        document.documentElement.style.setProperty('--header-menu-top', String(Math.round(header?.getBoundingClientRect().bottom ?? 64))+'px');
      }
    });
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); close(menu); button.focus(); }
    });
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-header-menu]')) closeAll();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeAll();
  });
  window.addEventListener('resize', () => closeAll());
})();
