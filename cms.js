(function () {
    'use strict';

    var API = 'api/content.php';
    var CACHE_KEY = 'omega_content';

    var EXCLUDE = [
        'script', 'style', 'noscript', 'form', 'button', 'select', 'textarea', '.modal', '.top_nav', '.down_block', '.devices_nav',
        '.menu_devices', '.control', '.category-shop-menu-list', '.nav_page', '#catalog', '#product', '#service', '#servicesGrid',
        '.shopping_items', '.cart_menu', '#gCartContent', '.popular_main', '#saleGrid', '.asaid_menu', '.asaid_menu_shopping',
        '.footer_block li a[href$=".html"]', '.serch_popup', '.to_top', '.cms_bar', '.cms_tools', '[data-cms-skip]',
        '.sort', '.num_sort_page', '.filter', '.slick-dots', '.slick-arrow', '.question_item i'
    ].join(',');
    var BLOCK_TAGS = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'LI', 'TD', 'TH', 'DT', 'DD', 'DIV', 'STRONG', 'SPAN', 'A'];
    var INLINE_TAGS = ['B', 'STRONG', 'I', 'EM', 'U', 'S', 'BR', 'A', 'SPAN', 'SMALL', 'SUP', 'SUB'];

    var content = {};
    var originals = new Map();
    var byKey = {};
    var editing = false;
    var active = null;

    function inOriginal(fn) {
        return window.OmegaI18n ? window.OmegaI18n.withOriginal(fn) : fn();
    }

    function hash(str) {
        var h = 0x811c9dc5;
        for (var i = 0; i < str.length; i++) {
            h ^= str.charCodeAt(i);
            h = Math.imul(h, 0x01000193);
        }
        return (h >>> 0).toString(36);
    }

    function norm(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }

    function isLeafBlock(el) {
        if (BLOCK_TAGS.indexOf(el.tagName) === -1) return false;

        if (el.tagName !== 'A' && el.querySelector('a[href^="tel:"], a[href^="mailto:"]')) return false;
        if (!norm(el.textContent) || norm(el.textContent).length > 3000) return false;
        var all = el.getElementsByTagName('*');
        for (var i = 0; i < all.length; i++) {
            if (INLINE_TAGS.indexOf(all[i].tagName) === -1) return false;
            if (all[i].tagName === 'A' && !/^(tel:|mailto:|https?:)/.test(all[i].getAttribute('href') || '')) {

                if (el.tagName !== 'A') return false;
            }
        }
        if (el.tagName === 'A' && !/^(tel:|mailto:)/.test(el.getAttribute('href') || '')) return false;
        return true;
    }

    function collect() {
        var root = document.body;
        byKey = {};
        root.querySelectorAll(BLOCK_TAGS.join(',')).forEach(function (el) {
            if (el.closest(EXCLUDE)) return;

            if (!el.dataset.cmsKey) {
                if (!isLeafBlock(el)) return;

                for (var p = el.parentElement; p && p !== root; p = p.parentElement) {
                    if (p.dataset && p.dataset.cmsKey) return;
                }
            }
            register(el, el.dataset.cmsKey || keyFor(el), el.innerHTML);
        });
        root.querySelectorAll('img').forEach(function (img) {
            if (img.closest(EXCLUDE) || img.closest('#catalogGrid, #productView, .img_gallary')) return;
            var src = img.getAttribute('src') || '';
            if (!img.dataset.cmsKey && (!src || /icons\/|no-image/.test(src))) return;
            register(img, img.dataset.cmsKey || 'i.' + hash(src.split('/').pop()), src);
        });
    }

    function keyFor(el) {
        var href = el.tagName === 'A' ? el.getAttribute('href') || '' : '';
        if (/^tel:/.test(href)) return 'h.tel.' + (el.textContent.replace(/\D/g, '').slice(-10) || hash(href));
        if (/^mailto:/.test(href)) return 'h.mail.' + hash(norm(el.textContent).toLowerCase());
        return 'h.' + hash(el.tagName + '|' + norm(el.textContent));
    }

    function register(el, key, original) {
        if (!originals.has(el)) originals.set(el, original);
        el.dataset.cmsKey = key;
        (byKey[key] = byKey[key] || []).push(el);
    }

    function fixLinks(el) {

        var links = el.tagName === 'A' ? [el] : Array.prototype.slice.call(el.querySelectorAll('a'));
        links.forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (/^tel:/.test(href)) a.setAttribute('href', 'tel:' + a.textContent.replace(/[^\d+]/g, ''));
            if (/^mailto:/.test(href)) a.setAttribute('href', 'mailto:' + norm(a.textContent));
        });
    }

    function applyTo(key) {
        (byKey[key] || []).forEach(function (el) {
            var c = content[key];
            if (el.tagName === 'IMG') {
                el.setAttribute('src', c ? c.value : originals.get(el));
                el.removeAttribute('srcset');
            } else {
                el.innerHTML = c ? c.value : originals.get(el);
                fixLinks(el);
            }
            el.classList.toggle('cms_changed', !!c);
        });
    }

    function applyAll() {
        Object.keys(byKey).forEach(function (key) { if (content[key]) applyTo(key); });
    }

    function load() {
        try { content = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}') || {}; } catch (e) { content = {}; }

        inOriginal(function () {
            collect();
            applyAll();
        });
        return fetch(API + '?action=all', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) return;
                var fresh = res.content || {};

                Object.keys(content).forEach(function (k) { if (!fresh[k]) { delete content[k]; applyTo(k); } });
                content = fresh;
                try { localStorage.setItem(CACHE_KEY, JSON.stringify(content)); } catch (e) {   }
                applyAll();
            })
            .catch(function () {   });
    }

    function post(action, fd) {
        return fetch(API + '?action=' + action, {
            method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
        }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Помилка сервера.' }; }); })
          .catch(function () { return { ok: false, message: 'Немає з\'єднання із сервером.' }; });
    }

    function toast(text, isError) {
        var t = document.querySelector('.cms_toast') || document.body.appendChild(Object.assign(document.createElement('div'), { className: 'cms_toast' }));
        t.textContent = text;
        t.classList.toggle('err', !!isError);
        t.classList.add('show');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(function () { t.classList.remove('show'); }, 2500);
    }

    function setupAdmin() {

        if (!window.OmegaImage) {
            var s = document.createElement('script');
            s.src = 'image-compress.js?v=1';
            document.head.appendChild(s);
        }
        var bar = document.createElement('div');
        bar.className = 'cms_bar';
        bar.innerHTML =
            '<button type="button" class="cms_toggle">✎ Редагувати сторінку</button>' +
            '<div class="cms_help" hidden>Клацніть по тексту, щоб змінити його, або по фото, щоб замінити. ' +
            'Товари й послуги — в <a href="admin.html" target="_blank">адмін-панелі</a>.</div>';

        var dataPage = document.querySelector('#product') ? 'Назва, ціна, опис і фото цього товару змінюються в адмін-панелі, вкладка «Товари».'
            : document.querySelector('#catalog') ? 'Товари й категорії каталогу змінюються в адмін-панелі, вкладки «Товари» і «Категорії».'
            : document.querySelector('#service, #servicesGrid') ? 'Тексти, ціни й фото послуг змінюються в адмін-панелі, вкладка «Послуги».'
            : document.querySelector('#reviewsList, .reviews_page') ? 'Відгуки публікуються й приховуються в адмін-панелі, вкладка «Відгуки».' : '';
        if (dataPage) {
            bar.querySelector('.cms_help').innerHTML = '<strong>На цій сторінці тут редагуються лише шапка й низ.</strong> ' + dataPage +
                ' <a href="admin.html" target="_blank">Відкрити адмін-панель</a>';
        }
        document.body.appendChild(bar);
        var tools = document.createElement('div');
        tools.className = 'cms_tools';
        tools.hidden = true;
        tools.innerHTML =
            '<button type="button" data-cmd="bold" title="Жирний"><b>Ж</b></button>' +
            '<button type="button" data-cmd="italic" title="Курсив"><i>К</i></button>' +
            '<button type="button" data-cmd="link" title="Посилання">🔗</button>' +
            '<span class="sep"></span>' +
            '<button type="button" data-act="save" class="save">Зберегти</button>' +
            '<button type="button" data-act="cancel">Скасувати</button>' +
            '<button type="button" data-act="reset" class="reset" title="Повернути початковий текст">↺ Початковий</button>';
        document.body.appendChild(tools);
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/jpeg,image/png,image/webp,image/gif';
        fileInput.hidden = true;
        document.body.appendChild(fileInput);

        var toggle = bar.querySelector('.cms_toggle');
        toggle.addEventListener('click', function () {
            editing = !editing;
            if (!editing) finish(false);
            document.documentElement.classList.toggle('cms_editing', editing);
            toggle.textContent = editing ? '✓ Завершити редагування' : '✎ Редагувати сторінку';
            bar.querySelector('.cms_help').hidden = !editing;
            if (editing) inOriginal(function () { collect(); applyAll(); });
        });

        function place() {
            if (!active) return;
            var r = active.getBoundingClientRect();
            tools.style.top = Math.max(8, window.scrollY + r.top - tools.offsetHeight - 8) + 'px';
            tools.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - tools.offsetWidth - 8)) + 'px';
        }

        function start(el) {
            if (active === el) return;
            finish(false);
            active = el;
            el.dataset.cmsBefore = el.innerHTML;
            el.contentEditable = 'true';
            el.classList.add('cms_active');
            tools.hidden = false;
            tools.querySelector('.reset').hidden = !content[el.dataset.cmsKey];
            place();
            el.focus();
        }

        function finish(save) {
            if (!active) return;
            var el = active;
            active = null;
            tools.hidden = true;
            el.contentEditable = 'false';
            el.classList.remove('cms_active');
            if (!save) {
                el.innerHTML = el.dataset.cmsBefore;
                delete el.dataset.cmsBefore;
                return;
            }
            var key = el.dataset.cmsKey;
            var fd = new FormData();
            fd.append('key', key);
            fd.append('type', 'html');
            fd.append('value', el.innerHTML);
            post('save', fd).then(function (res) {
                if (!res.ok) { el.innerHTML = el.dataset.cmsBefore; return toast(res.message || 'Не вдалося зберегти.', true); }
                content[key] = { type: 'html', value: res.value };
                try { localStorage.setItem(CACHE_KEY, JSON.stringify(content)); } catch (e) {}
                applyTo(key);
                var n = (byKey[key] || []).length;
                toast(n > 1 ? 'Збережено (змінено в ' + n + ' місцях)' : 'Збережено');
            });
            delete el.dataset.cmsBefore;
        }

        tools.addEventListener('mousedown', function (e) { e.preventDefault(); });
        tools.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn || !active) return;
            if (btn.dataset.cmd === 'link') {
                var href = prompt('Адреса посилання (https://…, tel:+380…, mailto:…):', 'https://');
                if (href) document.execCommand('createLink', false, href);
            } else if (btn.dataset.cmd) {
                document.execCommand(btn.dataset.cmd);
            }
            if (btn.dataset.act === 'save') finish(true);
            if (btn.dataset.act === 'cancel') finish(false);
            if (btn.dataset.act === 'reset') {
                var key = active.dataset.cmsKey;
                if (!confirm('Повернути початковий текст?')) return;
                finish(false);
                var fd = new FormData();
                fd.append('key', key);
                post('reset', fd).then(function (res) {
                    if (!res.ok) return toast(res.message || 'Не вдалося.', true);
                    delete content[key];
                    try { localStorage.setItem(CACHE_KEY, JSON.stringify(content)); } catch (e2) {}
                    applyTo(key);
                    toast('Повернуто початковий текст');
                });
            }
        });

        var imageTarget = null;
        document.addEventListener('click', function (e) {
            if (!editing) return;
            if (e.target.closest('.cms_bar, .cms_tools')) return;
            var img = e.target.closest('img[data-cms-key]');
            if (img) {
                e.preventDefault();
                e.stopPropagation();
                finish(true);
                imageTarget = img;
                var key = img.dataset.cmsKey;
                if (content[key] && confirm('Повернути початкове фото? (Скасувати — обрати нове фото)')) {
                    var fdr = new FormData();
                    fdr.append('key', key);
                    post('reset', fdr).then(function (res) {
                        if (!res.ok) return toast(res.message || 'Не вдалося.', true);
                        delete content[key];
                        applyTo(key);
                        toast('Повернуто початкове фото');
                    });
                    return;
                }
                fileInput.value = '';
                fileInput.click();
                return;
            }
            var el = e.target.closest('[data-cms-key]');
            if (el && el.dataset.cmsKey.charAt(0) === 'h') {
                e.preventDefault();
                e.stopPropagation();
                start(el);
                return;
            }
            if (active && !e.target.closest('.cms_active')) finish(true);

            if (e.target.closest('a')) e.preventDefault();
        }, true);

        fileInput.addEventListener('change', function () {
            if (!fileInput.files.length || !imageTarget) return;
            var key = imageTarget.dataset.cmsKey;
            var fd = new FormData();
            fd.append('key', key);
            fd.append('type', 'image');
            var note = '';
            toast('Підготовка фото…');

            var compress = window.OmegaImage ? window.OmegaImage.compress(fileInput.files[0]) : Promise.resolve({ file: fileInput.files[0] });
            compress.then(function (res) {
                fd.append('image', res.file, res.file.name);
                note = window.OmegaImage ? window.OmegaImage.describe(res) : '';
                toast('Завантаження фото…');
                return post('save', fd);
            }).then(function (res) {
                if (!res.ok) return toast((res.errors && res.errors.image) || res.message || 'Не вдалося завантажити.', true);
                content[key] = { type: 'image', value: res.value };
                try { localStorage.setItem(CACHE_KEY, JSON.stringify(content)); } catch (e) {}
                applyTo(key);
                toast('Фото замінено' + (note ? '. ' + note : ''));
            });
        });

        document.addEventListener('keydown', function (e) {
            if (!active) return;
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || /^(H\d|SPAN|A|STRONG|TD|TH|DT)$/.test(active.tagName))) {
                e.preventDefault();
                finish(true);
            }
        });
        window.addEventListener('scroll', place, { passive: true });
        window.addEventListener('resize', place);
    }

    function init() {
        load();
        fetch('auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {

                var translated = window.OmegaI18n && window.OmegaI18n.lang !== 'uk';
                if (res.user && res.user.is_admin && !translated) setupAdmin();
            })
            .catch(function () {});
    }

    var started = false;
    function start() {
        if (started) return;
        started = true;
        init();
    }
    if (document.readyState === 'complete') start();
    else {
        window.addEventListener('load', start);
        document.addEventListener('DOMContentLoaded', function () { setTimeout(start, 1500); });
        if (document.readyState === 'interactive') setTimeout(start, 1500);
    }
})();
