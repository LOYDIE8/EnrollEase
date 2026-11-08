(() => {
  const sidebar = document.querySelector('#adminSidebar');
  const menuButton = document.querySelector('#adminMenuButton');
  const overlay = document.querySelector('#adminSidebarOverlay');

  if (!sidebar || !menuButton || !overlay) return;

  const closeMenu = () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Open admin navigation');
  };

  menuButton.addEventListener('click', () => {
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
    menuButton.setAttribute('aria-expanded', String(isOpen));
    menuButton.setAttribute('aria-label', isOpen ? 'Close admin navigation' : 'Open admin navigation');
  });

  overlay.addEventListener('click', closeMenu);
  sidebar.querySelectorAll('.nav-link').forEach(link => link.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeMenu();
  });
})();
