(() => {
  const currentUrl = new URL(location.href);
  const currentFile = currentUrl.pathname.split('/').pop().toLowerCase();

  if (currentFile === 'admin-dashboard.html') {
    location.replace(new URL('admin/dashboard.php', currentUrl.href).href);
  }
})();
