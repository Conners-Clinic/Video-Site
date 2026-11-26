(function () {
  'use strict';
  var mobile = window.matchMedia('(max-width: 1251px)');
  var body = document.body;

  function sync() {
    var button = document.getElementById('open_slide');
    if (!button) return;
    var open = body.classList.contains(mobile.matches ? 'mobi_side_open' : 'side_open');
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    button.title = open ? 'Close menu' : 'Open menu';
  }

  function rememberDesktop() {
    // Mobile drawers always start closed, independently of the desktop preference.
    if (body.classList.contains('side_open')) {
      window.SlideSetCookie('open_slide', 'yes', 1);
    } else {
      window.SlideEraseCookie('open_slide');
    }
  }

  function close() {
    body.classList.remove(mobile.matches ? 'mobi_side_open' : 'side_open');
    if (!mobile.matches) rememberDesktop();
    sync();
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('#open_slide')) {
      body.classList.toggle(mobile.matches ? 'mobi_side_open' : 'side_open');
      if (!mobile.matches) rememberDesktop();
      sync();
    } else if (event.target.closest('[data-sidebar-close]')) {
      close();
    } else if (mobile.matches && event.target.closest('#site-sidebar a[href]')) {
      close();
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && mobile.matches && body.classList.contains('mobi_side_open')) {
      close();
      document.getElementById('open_slide').focus();
    }
  });
  mobile.addEventListener('change', function () {
    body.classList.remove('mobi_side_open');
    sync();
  });
  // Player expansion and soft navigation also update the drawer's body classes.
  new MutationObserver(sync).observe(body, { attributes: true, attributeFilter: ['class'] });
  sync();
}());
