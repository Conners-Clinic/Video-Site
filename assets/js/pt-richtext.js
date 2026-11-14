(function (global) {
    'use strict';
    var supportedPlugins = new Set([
        'advlist', 'anchor', 'autolink', 'autoresize', 'autosave', 'charmap', 'code',
        'codesample', 'directionality', 'emoticons', 'fullscreen', 'help', 'image',
        'importcss', 'insertdatetime', 'link', 'lists', 'media', 'nonbreaking',
        'pagebreak', 'preview', 'quickbars', 'save', 'searchreplace', 'table',
        'visualblocks', 'visualchars', 'wordcount'
    ]);
    var toolbarNames = { styleselect: 'styles', formatselect: 'blocks', fontselect: 'fontfamily', fontsizeselect: 'fontsize' };
    var unavailableButtons = new Set(['insertfile', 'uploadImages', 'fullpage']);
    function toolbar(value) {
        return String(value || '').split(/\s+/).map(function (name) {
            return unavailableButtons.has(name) ? '' : (toolbarNames[name] || name);
        }).filter(Boolean).join(' ').replace(/(?:\|\s*){2,}/g, '| ').replace(/^\s*\||\|\s*$/g, '').trim();
    }
    function prepare(options) {
        var cfg = Object.assign({}, options);
        var plugins = (Array.isArray(cfg.plugins) ? cfg.plugins.join(' ') : String(cfg.plugins || '')).split(/\s+/);
        cfg.plugins = Array.from(new Set(plugins.filter(function (name) { return supportedPlugins.has(name); }))).join(' ');
        if (cfg.toolbar1 || cfg.toolbar2) {
            cfg.toolbar = [toolbar(cfg.toolbar1), toolbar(cfg.toolbar2)].filter(Boolean);
            delete cfg.toolbar1;
            delete cfg.toolbar2;
        } else if (cfg.toolbar) {
            cfg.toolbar = Array.isArray(cfg.toolbar) ? cfg.toolbar.map(toolbar) : toolbar(cfg.toolbar);
        }
        if (cfg.theme === 'modern' || cfg.theme === 'mobile') { cfg.theme = 'silver'; }
        if (cfg.skin === 'lightgray') { cfg.skin = 'oxide'; }
        cfg.license_key = 'gpl';
        cfg.cache_suffix = '?securityrev=8.10.2-20261111';
        cfg.promotion = false;
        cfg.browser_spellcheck = true;
        cfg.xss_sanitization = true;
        cfg.sandbox_iframes = true;
        cfg.convert_unsafe_embeds = true;
        return cfg;
    }
    function fallback(cfg, error) {
        var fields = cfg.target ? [cfg.target] : document.querySelectorAll(cfg.selector || 'textarea');
        Array.from(fields).forEach(function (field) {
            var editor = global.tinymce && global.tinymce.get(field.id);
            if (editor) { editor.remove(); }
            field.hidden = false;
            field.style.display = '';
            field.style.visibility = 'visible';
            field.style.minHeight = String(cfg.height || 270) + 'px';
            field.style.resize = 'none';
            var id = field.id + '-richtext-error';
            var notice = document.getElementById(id);
            if (!notice) {
                notice = document.createElement('p');
                notice.id = id;
                notice.setAttribute('role', 'status');
                notice.textContent = 'The editor could not load. You can continue editing the text below.';
                field.insertAdjacentElement('beforebegin', notice);
            }
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
        });
        console.error('Rich text editor unavailable:', error && error.message ? error.message : 'initialization failed');
        return [];
    }
    global.PT_InitRichText = function (options) {
        var cfg = prepare(options || {});
        if (!global.tinymce) { return Promise.resolve(fallback(cfg, new Error('library unavailable'))); }
        var timer;
        var timedOut = false;
        var setup = cfg.setup;
        cfg.setup = function (editor) {
            if (setup) { setup(editor); }
            editor.on('init', function () { if (timedOut) { editor.remove(); } });
        };
        var deadline = new Promise(function (_, reject) {
            timer = global.setTimeout(function () {
                timedOut = true;
                reject(new Error('initialization timed out'));
            }, 15000);
        });
        return Promise.race([Promise.resolve().then(function () { return global.tinymce.init(cfg); }), deadline]).then(function (editors) {
            global.clearTimeout(timer);
            return editors;
        }).catch(function (error) {
            global.clearTimeout(timer);
            return fallback(cfg, error);
        });
    };
})(window);
