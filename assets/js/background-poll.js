(function (root) {
    'use strict';
    function create(options) {
        var timer = null, busy = false, queued = null, failures = 0, stopped = false;
        function clear() { if (timer !== null) { options.clearTimer(timer); timer = null; } }
        function schedule() {
            clear();
            if (!stopped && !options.hidden()) {
                timer = options.setTimer(function () { timer = null; refresh(); }, Math.min(300000, 30000 * Math.pow(2, failures)));
            }
        }
        function refresh(args) {
            clear();
            if (stopped || options.hidden()) { return; }
            if (busy) { if (args) { queued = args; } return; }
            busy = true;
            options.request(args || null, function (success) {
                busy = false; failures = success ? 0 : Math.min(4, failures + 1);
                if (queued) { var next = queued; queued = null; refresh(next); }
                else { schedule(); }
            });
        }
        return {
            refresh: refresh,
            visibility: function () { clear(); if (!options.hidden()) { refresh(); } },
            stop: function () { stopped = true; queued = null; clear(); }
        };
    }
    root.PTBackgroundPoll = {create: create};
    if (typeof module !== 'undefined') { module.exports = root.PTBackgroundPoll; }
}(typeof window !== 'undefined' ? window : globalThis));
