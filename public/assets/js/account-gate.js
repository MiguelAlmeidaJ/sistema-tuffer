(() => {
  'use strict';
  const dialog = document.querySelector('[data-account-gate]');
  if (!dialog || !('showModal' in dialog)) return;
  const delay = Number(dialog.dataset.delay || 5000);
  // A apresentação ocorre uma única vez nesta página. A autenticação acontece
  // exclusivamente nas rotas do servidor, sem gravar senhas no navegador.
  const show = () => {
    if (dialog.isConnected && !dialog.open) {
      dialog.showModal();
      const first = dialog.querySelector('.account-gate__google, #account-gate-email');
      first?.focus({ preventScroll: true });
    }
  };
  // O modal é intencionalmente obrigatório na experiência pública para visitantes.
  dialog.addEventListener('cancel', event => event.preventDefault());
  // Um clique no backdrop não fecha o modal.
  window.setTimeout(show, Number.isFinite(delay) ? Math.max(0, delay) : 5000);
})();
