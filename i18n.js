(function () {
    'use strict';

    var VERSION = '15';
    var LANGS = { uk: 'Українська', ru: 'Русский', en: 'English' };
    var HTML_LANG = { uk: 'uk', ru: 'ru', en: 'en' };
    var ATTRS = ['placeholder', 'title', 'alt', 'aria-label'];
    var CYR = /[А-Яа-яІіЇїЄєҐґ]/;

    var lang = 'uk';
    try { lang = localStorage.getItem('omega_lang') || 'uk'; } catch (e) {   }
    if (!LANGS[lang]) lang = 'uk';
    document.documentElement.lang = HTML_LANG[lang];

    var dict = null, compiled = [], observer = null;
    var texts = new Map();
    var attrs = new Map();

    function exact(core) {
        var e = dict.exact;
        if (e[core] !== undefined) return e[core];

        var fixed = core.replace(/i/g, 'і').replace(/I/g, 'І');
        return e[fixed];
    }

    function part(value) {
        var found = exact(value);
        if (found !== undefined) return found;
        var cap = value.charAt(0).toUpperCase() + value.slice(1);
        found = cap !== value ? exact(cap) : undefined;
        if (found !== undefined) return found.charAt(0).toLowerCase() + found.slice(1);

        found = depth < 2 ? translate(value) : undefined;
        return found !== undefined ? found : value;
    }

    var depth = 0;

    function translate(core) {
        depth++;
        try { return lookup(core); } finally { depth--; }
    }

    function lookup(core) {
        var found = exact(core);
        if (found !== undefined) return found;
        for (var i = 0; i < compiled.length; i++) {
            var m = compiled[i][0].exec(core);
            if (m) {
                return compiled[i][1].replace(/\$(t?)(\d)/g, function (_, tr, n) {
                    var v = m[+n] === undefined ? '' : m[+n];
                    return tr ? part(v) : v;
                });
            }
        }
        var p = dict.prefixes;
        for (i = 0; i < p.length; i++) {
            if (core.lastIndexOf(p[i][0], 0) === 0 && /[\s,.]/.test(core.charAt(p[i][0].length))) {
                return p[i][1] + core.slice(p[i][0].length);
            }
        }
        return undefined;
    }

    function skipped(el) {
        if (!el || el.nodeType !== 1) return false;
        var tag = el.tagName;
        return tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'NOSCRIPT' || el.isContentEditable ||
            (el.closest && !!el.closest('[data-no-i18n]'));
    }

    function textNode(node) {
        var raw = node.nodeValue, state = texts.get(node);
        if (state && state.out === raw) return;
        if (!raw || !CYR.test(raw) || skipped(node.parentNode)) { if (state) texts.delete(node); return; }
        var core = raw.replace(/\s+/g, ' ').trim();
        var out = translate(core);
        if (out === undefined) { if (state) texts.delete(node); return; }
        out = raw.match(/^\s*/)[0] + out + raw.match(/\s*$/)[0];
        texts.set(node, { orig: raw, out: out });
        node.nodeValue = out;
    }

    function attribute(el, name) {
        var raw = el.getAttribute(name), state = attrs.get(el);
        if (raw === null || (state && state[name] && state[name].out === raw)) return;
        if (!CYR.test(raw)) return;
        var out = translate(raw.replace(/\s+/g, ' ').trim());
        if (out === undefined) return;
        if (!state) attrs.set(el, state = {});
        state[name] = { orig: raw, out: out };
        el.setAttribute(name, out);
    }

    function element(el) {

        if (el.tagName !== 'TEXTAREA' && skipped(el)) return;
        if (el.closest('[data-no-i18n]')) return;
        for (var i = 0; i < ATTRS.length; i++) if (el.hasAttribute(ATTRS[i])) attribute(el, ATTRS[i]);

        if (el.tagName === 'INPUT' && /^(button|submit)$/.test(el.type) && el.hasAttribute('value')) attribute(el, 'value');
    }

    function walk(root) {
        if (root.nodeType === 3) return textNode(root);
        if (root.nodeType !== 1 && root.nodeType !== 9) return;
        if (root.nodeType === 1) { if (skipped(root)) return; element(root); }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null);
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeType === 3) textNode(node); else element(node);
        }
    }

    var burst = 0, burstStart = 0, stopped = false;

    function onMutations(list) {

        var now = Date.now();
        if (now - burstStart > 1000) { burstStart = now; burst = 0; }
        if (stopped) return;
        if (++burst > 400) {
            stopped = true;
            observer.disconnect();
            var m0 = list[0], t = m0.target;
            window.OmegaI18n.stopped = m0.type + ' ' + (m0.attributeName || '') + ' ' + (t.nodeType === 3 ? '#text in ' + (t.parentNode && t.parentNode.nodeName) + ': ' + String(t.nodeValue).slice(0, 60) : t.nodeName + '.' + t.className);
            if (window.console) console.error('i18n: переклад зупинено (цикл змін): ' + window.OmegaI18n.stopped);
            return;
        }
        for (var i = 0; i < list.length; i++) {
            var m = list[i];
            if (m.type === 'characterData') textNode(m.target);
            else if (m.type === 'attributes') element(m.target);
            else for (var k = 0; k < m.addedNodes.length; k++) walk(m.addedNodes[k]);
        }
    }

    function observe() {
        if (stopped) return;
        observer.observe(document.documentElement, {
            childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS.concat('value')
        });
    }

    function start(data) {
        dict = data;
        compiled = (dict.patterns || []).map(function (p) { return [new RegExp(p[0]), p[1]]; });
        observer = new MutationObserver(onMutations);
        walk(document.documentElement);
        observe();
        document.documentElement.classList.remove('i18n-wait');
    }

    function withOriginal(fn) {
        if (!dict) return fn();
        observer.disconnect();
        texts.forEach(function (state, node) {
            if (!node.isConnected) { texts.delete(node); return; }
            if (node.nodeValue === state.out) node.nodeValue = state.orig;
        });
        attrs.forEach(function (state, el) {
            if (!el.isConnected) { attrs.delete(el); return; }
            Object.keys(state).forEach(function (name) {
                if (el.getAttribute(name) === state[name].out) el.setAttribute(name, state[name].orig);
            });
        });
        texts.clear();
        attrs.clear();
        try { return fn(); } finally {
            walk(document.documentElement);
            observe();
        }
    }

    function set(next) {
        if (!LANGS[next] || next === lang) return;
        try { localStorage.setItem('omega_lang', next); } catch (e) {   }
        location.reload();
    }

    window.OmegaI18n = {
        lang: lang, langs: LANGS, set: set, withOriginal: withOriginal,
        t: function (text) { var out = dict ? translate(text) : undefined; return out === undefined ? text : out; }
    };

    function buildSwitchers() {
        var others = Object.keys(LANGS).filter(function (k) { return k !== lang; });
        document.querySelectorAll('.control .click_main').forEach(function (li) {
            var list = li.querySelector('.control_item');

            if (!list || !li.querySelector('.fa-globe')) return;
            li.setAttribute('data-no-i18n', '');
            Array.prototype.slice.call(li.childNodes).forEach(function (n) { if (n.nodeType === 3) li.removeChild(n); });
            li.insertBefore(document.createTextNode(LANGS[lang]), list);
            list.innerHTML = others.map(function (k) {
                return '<li class="lang"><a href="#" data-lang="' + k + '" lang="' + HTML_LANG[k] + '">' + LANGS[k] + '</a></li>';
            }).join('');
        });
        document.querySelectorAll('.menu_footer ul').forEach(function (ul) {
            ul.setAttribute('data-no-i18n', '');
            ul.innerHTML = Object.keys(LANGS).map(function (k) {
                return '<li><a href="#" data-lang="' + k + '" lang="' + HTML_LANG[k] + '"' + (k === lang ? ' class="active" aria-current="true"' : '') + '>' + LANGS[k] + '</a></li>';
            }).join('');
        });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-lang]') : null;
        if (!link) return;
        e.preventDefault();
        set(link.getAttribute('data-lang'));
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildSwitchers);
    else buildSwitchers();

    if (lang === 'uk') return;

    var cacheKey = 'omega_i18n_' + lang + '_' + VERSION, cached = null;
    try { cached = JSON.parse(localStorage.getItem(cacheKey) || 'null'); } catch (e) { cached = null; }
    if (cached && cached.exact) { start(cached); return; }

    var style = document.createElement('style');
    style.textContent = 'html.i18n-wait body{visibility:hidden}';
    document.head.appendChild(style);
    document.documentElement.classList.add('i18n-wait');
    setTimeout(function () { document.documentElement.classList.remove('i18n-wait'); }, 2500);

    fetch('lang/' + lang + '.json?v=' + VERSION).then(function (r) { return r.json(); }).then(function (data) {
        try {
            Object.keys(localStorage).forEach(function (k) { if (/^omega_i18n_/.test(k)) localStorage.removeItem(k); });
            localStorage.setItem(cacheKey, JSON.stringify(data));
        } catch (e) {   }
        start(data);
    }).catch(function () {
        document.documentElement.classList.remove('i18n-wait');
    });
})();
