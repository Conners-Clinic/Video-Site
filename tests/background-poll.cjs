'use strict';
const assert = require('node:assert/strict');
const {create} = require('../assets/js/background-poll.js');
let hidden = false, timer = null, calls = [];
const poll = create({hidden: () => hidden, setTimer(fn, ms) { timer = {fn, ms}; return 1; }, clearTimer() { timer = null; }, request(args, done) { calls.push({args, done}); }});
function tick() { const fn = timer.fn; timer = null; fn(); }
poll.refresh(); poll.refresh(); assert.equal(calls.length, 1);
poll.refresh({sa: 1}); assert.equal(calls.length, 1);
calls[0].done(true); assert.equal(calls.length, 2); assert.deepEqual(calls[1].args, {sa: 1});
calls[1].done(true); assert.equal(timer.ms, 30000);
tick(); assert.equal(calls.length, 3); calls[2].done(false); assert.equal(timer.ms, 60000);
tick(); calls[3].done(false); assert.equal(timer.ms, 120000);
hidden = true; poll.visibility(); assert.equal(timer, null); poll.refresh(); assert.equal(calls.length, 4);
hidden = false; poll.visibility(); assert.equal(calls.length, 5); calls[4].done(true); assert.equal(timer.ms, 30000);
tick(); hidden = true; poll.visibility(); calls[5].done(true); assert.equal(timer, null);
hidden = false; poll.visibility(); poll.stop(); calls[6].done(true); assert.equal(timer, null); poll.refresh(); assert.equal(calls.length, 7);
console.log('Visible/hidden, single-flight, queued-open, error-backoff and shutdown checks passed');
