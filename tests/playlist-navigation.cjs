const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let attrs = {}, items = [], first = {href: 'related'};
const container = {
  getAttribute: key => attrs[key] ?? null,
  querySelector: () => first,
  querySelectorAll: () => items
};
const context = {window: {}, document: {getElementById: () => container}};
vm.runInNewContext(fs.readFileSync('assets/js/playlist-navigation.js', 'utf8'), context);
const next = context.window.PT_NextWatchUrl;
const item = id => ({getAttribute: () => String(id), querySelector: () => ({href: 'video-' + id})});
assert.equal(next(), 'related');
attrs = {'data-playlist-current': '50', 'data-next-url': 'video-51', 'data-after-window-url': 'video-51'};
items = Array.from({length: 50}, (_,i) => item(i + 1));
assert.equal(next(), 'video-51', 'boundary autoplay');
attrs['data-playlist-current'] = '49';
assert.equal(next(), 'video-50');
items = [item(49), item(47), item(48)];
assert.equal(next(), 'video-47', 'reflect reordered visible neighbors');
items = [item(51), item(52)];
assert.equal(next(), 'video-51', 'browsing a different window retains current autoplay');
attrs['data-playlist-current'] = '52'; attrs['data-after-window-url'] = '';
assert.equal(next(), '', 'last item does not follow pagination links');
console.log('Playlist navigation boundary and reorder tests passed');
