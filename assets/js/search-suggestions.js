(function (root) {
    'use strict';
    function controller(options) {
        var timer = null, pending = null, generation = 0, composing = false;
        function cancel() {
            generation++;
            if (timer !== null) { options.clearTimer(timer); timer = null; }
            if (pending) { pending.abort(); pending = null; }
        }
        function input(value) {
            cancel(); options.render(null);
            var term = value.trim().replace(/\s+/g, ' ');
            if (composing || Array.from(term).length < 2 || Array.from(term).length > 200 || term.split(' ').length > 12) { return; }
            var current = generation;
            timer = options.setTimer(function () {
                timer = null;
                pending = options.request(term, function (result) {
                    if (current === generation) { pending = null; options.render(result); }
                });
            }, 300);
        }
        return {
            input: input,
            compositionStart: function () { composing = true; cancel(); options.render(null); },
            compositionEnd: function (value) { composing = false; input(value); },
            cancel: cancel,
            dismiss: function () { cancel(); options.render(null); }
        };
    }
    root.PTSearchSuggestions = {
        controller: controller,
        mount: function ($, url) {
            var input = $('#search-bar'), dropdown = input.closest('form').find('.search-dropdown');
            var previous = input.data('pt-suggestions');
            if (previous) { previous.cancel(); }
            var search = controller({
                setTimer: root.setTimeout.bind(root), clearTimer: root.clearTimeout.bind(root),
                request: function (term, done) {
                    return $.post(url, {search_value: term}).done(function (data) { done(data); }).fail(function () { done(null); });
                },
                render: function (data) {
                    if (data && data.status === 200 && data.html) { dropdown.html(data.html).removeClass('hidden'); }
                    else { dropdown.empty().addClass('hidden'); }
                }
            });
            input.data('pt-suggestions', search).off('.ptSuggestions')
                .on('input.ptSuggestions', function () { search.input(this.value); })
                .on('compositionstart.ptSuggestions', search.compositionStart)
                .on('compositionend.ptSuggestions', function () { search.compositionEnd(this.value); })
                .on('keydown.ptSuggestions', function (event) { if (event.key === 'Escape') { search.dismiss(); } });
            input.closest('form').off('submit.ptSuggestions').on('submit.ptSuggestions', search.cancel);
            $(document).off('click.ptSuggestions').on('click.ptSuggestions', function (event) {
                if (!$(event.target).closest('.search-header').length) { search.dismiss(); }
            });
        }
    };
    if (typeof module !== 'undefined') { module.exports = root.PTSearchSuggestions; }
}(typeof window !== 'undefined' ? window : globalThis));
