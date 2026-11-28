'use strict';
const assert = require('node:assert/strict');
const {controller} = require('../assets/js/search-suggestions.js');
let next = 0, rendered = null;
const timers = new Map(), calls = [];
const search = controller({
    setTimer(fn, ms) { assert.equal(ms, 300); timers.set(++next, fn); return next; },
    clearTimer(id) { timers.delete(id); },
    render(result) { rendered = result; },
    request(term, done) { const request = {term, done, aborted: false, abort() { this.aborted = true; }}; calls.push(request); return request; }
});
function flush() { const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn()); }
for (const value of ['R', 'Ri', 'Rif', 'Rife']) { search.input(value); }
assert.equal(calls.length, 0); assert.equal(timers.size, 1); flush();
assert.equal(calls.length, 1); assert.equal(calls[0].term, 'Rife');
search.input('PectaSol'); assert.equal(calls[0].aborted, true); flush();
calls[1].done({html: 'new'}); calls[0].done({html: 'old'}); assert.deepEqual(rendered, {html: 'new'});
search.input(''); assert.equal(rendered, null); assert.equal(timers.size, 0);
search.input('I'); assert.equal(timers.size, 0);
search.input('IV'); flush(); assert.equal(calls.at(-1).term, 'IV');
search.compositionStart(); search.input('中文'); assert.equal(timers.size, 0);
search.compositionEnd('中文'); flush(); assert.equal(calls.at(-1).term, '中文');
search.input('5-FU'); flush(); assert.equal(calls.at(-1).term, '5-FU');
search.input('a'.repeat(201)); assert.equal(timers.size, 0);
search.input(Array(13).fill('IV').join(' ')); assert.equal(timers.size, 0);
search.input('B12'); search.dismiss(); flush(); assert.equal(rendered, null);
const finalRequest = calls.at(-1); finalRequest.done({html: 'late'}); assert.equal(rendered, null);
console.log('Search typing, paste/composition, cancellation, bounds and stale-response checks passed');
