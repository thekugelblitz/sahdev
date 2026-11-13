(() => {
  const toggle = document.querySelector('.nav-toggle');
  const links = document.getElementById('site-links');
  if (!toggle || !links) return;
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded',String(open)); links.classList.toggle('is-open',open);
  });
  document.addEventListener('keydown',event => { if (event.key === 'Escape') { toggle.setAttribute('aria-expanded','false'); links.classList.remove('is-open'); } });
})();
