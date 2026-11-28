(function (root) {
    'use strict';
    root.PT_NextWatchUrl = function () {
        var container = document.getElementById('next-video');
        if (!container) return '';
        var currentId = container.getAttribute('data-playlist-current');
        if (!currentId) {
            var first = container.querySelector('a[href]');
            return first ? first.href : '';
        }
        var items = container.querySelectorAll('[data-litsitem-id]');
        for (var i = 0; i < items.length; i++) {
            if (items[i].getAttribute('data-litsitem-id') === currentId) {
                var next = items[i + 1] && items[i + 1].querySelector('a[href]');
                return next ? next.href : (container.getAttribute('data-after-window-url') || '');
            }
        }
        return container.getAttribute('data-next-url') || '';
    };
}(window));
