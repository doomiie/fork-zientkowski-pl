document.addEventListener('DOMContentLoaded', () => {
  const loader = document.getElementById('page-loader');
  if (!loader) return;
  window.addEventListener('load', () => {
    loader.classList.add('fade-out');
    setTimeout(() => loader.remove(), 300);
  });
});
