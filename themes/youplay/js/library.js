/* Progressive watch-page enhancements. Loaded once; also supports PlayTube soft navigation. */
(function () {
    'use strict';
    var desktop = window.matchMedia('(min-width: 993px)');

    function selectTab(root, name, focus) {
        root.querySelectorAll('[data-watch-tab]').forEach(function (tab) {
            var active = tab.dataset.watchTab === name;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        root.querySelectorAll('[data-watch-panel]').forEach(function (panel) {
            panel.hidden = panel.dataset.watchPanel !== name;
        });
    }

    function placeSecondary(page) {
        var cta = page.querySelector('[data-watch-secondary]');
        if (!cta) return;
        var targetName = desktop.matches && !page.classList.contains('cc-watch-playlist') ? 'desktop' : 'mobile';
        var target = page.querySelector('[data-watch-secondary-target="' + targetName + '"]');
        if (target && cta.parentNode !== target) target.appendChild(cta);
    }

    function revealHash(page) {
        if (!window.location.hash) return;
        var target;
        try { target = document.getElementById(decodeURIComponent(window.location.hash.slice(1))); } catch (_) { return; }
        if (!target || !page.contains(target)) return;
        var panel = target.closest('[data-watch-panel]');
        var root = page.querySelector('[data-watch-content]');
        if (panel && root) selectTab(root, panel.dataset.watchPanel, false);
        for (var parent = target; parent && parent !== page; parent = parent.parentElement) {
            if (parent.tagName === 'DETAILS') parent.open = true;
        }
    }

    function initialize() {
        var page = document.querySelector('.cc-watch');
        if (!page) return;
        var root = page.querySelector('[data-watch-content]');
        if (root && !root.dataset.enhanced) {
            root.dataset.enhanced = 'true';
            var tabs = root.querySelector('.watch-content-tabs');
            if (tabs) {
                tabs.setAttribute('role', 'tablist');
                tabs.querySelectorAll('[data-watch-tab]').forEach(function (tab) {
                    var name = tab.dataset.watchTab;
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-controls', 'watch-' + name);
                    var panel = root.querySelector('[data-watch-panel="' + name + '"]');
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('aria-labelledby', tab.id);
                    panel.tabIndex = 0;
                });
                // Transcript is fully readable within its tab; keep the original toggle for no-JS/other themes.
                var transcript = root.querySelector('#watch-transcript-panel');
                if (transcript) transcript.hidden = false;
                var toggle = root.querySelector('.watch-transcript-toggle');
                if (toggle) toggle.hidden = true;
                selectTab(root, 'overview', false);
            }
        }
        // Moving the existing node preserves custom HTML, IDs, tracking and form state.
        placeSecondary(page);
        revealHash(page);
    }

    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-watch-tab]');
        if (tab) {
            event.preventDefault();
            selectTab(tab.closest('[data-watch-content]'), tab.dataset.watchTab, false);
        }
        if (!event.target.closest('.cc-watch-more')) {
            document.querySelectorAll('.cc-watch-more[open]').forEach(function (menu) { menu.open = false; });
        }
        var action = event.target.closest('.cc-watch-more-menu button, .cc-watch-more-menu a');
        if (action) action.closest('details').open = false;
    });
    document.addEventListener('keydown', function (event) {
        var tab = event.target.closest('[data-watch-tab]');
        if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) !== -1) {
            event.preventDefault();
            var root = tab.closest('[data-watch-content]');
            var tabs = Array.from(root.querySelectorAll('[data-watch-tab]'));
            var index = tabs.indexOf(tab);
            if (event.key === 'Home') index = 0;
            else if (event.key === 'End') index = tabs.length - 1;
            else index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            selectTab(root, tabs[index].dataset.watchTab, true);
        }
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.cc-watch .like-btn[role="button"]')) {
            event.preventDefault();
            event.target.click();
        }
        if (event.key === 'Escape') {
            var more = event.target.closest('.cc-watch-more[open]');
            if (more) { more.open = false; more.querySelector('summary').focus(); }
        }
    });
    window.addEventListener('hashchange', initialize);
    desktop.addEventListener('change', initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
    function start() {
        initialize();
        // Observe only page replacement, not every interaction inside the video player.
        var container = document.getElementById('container_content');
        if (container) new MutationObserver(initialize).observe(container, { childList: true });
    }
})();
