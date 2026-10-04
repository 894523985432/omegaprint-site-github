(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var menu = document.getElementById('main_menu');

    var toTop = document.createElement('button');
    toTop.type = 'button';
    toTop.className = 'to_top';
    toTop.setAttribute('aria-label', 'Нагору');
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' }); });
    document.body.appendChild(toTop);

    var ticking = false;
    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            var y = window.scrollY || document.documentElement.scrollTop;
            if (menu) menu.classList.toggle('is-scrolled', y > 140);
            toTop.classList.toggle('show', y > 600);
            ticking = false;
        });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (reduceMotion || !('IntersectionObserver' in window)) return;
    var SELECTORS = [
        '.block_services', '.block_loz', '.poruct_block', '.content h1', '.content h2', 'footer .row > div',
        '.asaid_menu', '.about_info', '.line_p', '.task_block', '.question_block > li', '.product_description',
        '.way_pay', '.catalog_empty', '#repairs .item'
    ];
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    function prepare(root) {
        var viewport = window.innerHeight;
        (root || document).querySelectorAll(SELECTORS.join(',')).forEach(function (el, i) {
            if (el.classList.contains('reveal') || el.closest('.slick-slider, .modal, .control_item, .down_block')) return;
            if (el.getBoundingClientRect().top < viewport * 0.9) return;
            el.classList.add('reveal');
            el.style.transitionDelay = (i % 4) * 60 + 'ms';
            observer.observe(el);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { prepare(); });
    else prepare();

    var grids = document.querySelectorAll('#catalogGrid, #saleGrid');
    if ('MutationObserver' in window) {
        grids.forEach(function (grid) {
            new MutationObserver(function () { prepare(grid); }).observe(grid, { childList: true });
        });
    }
})();

(function () {
    var $ = window.jQuery;
    if (!$ || !window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    $(function () {
        $('.work_time').off('click').on('mouseenter', function () {
            $(this).find('.all_time').stop(true, true).slideDown(150);
        });
    });
})();

(function () {
    var DAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

    function kyivNow() {
        var zones = ['Europe/Kyiv', 'Europe/Kiev'];
        for (var i = 0; i < zones.length; i++) {
            try {
                var parts = new Intl.DateTimeFormat('en-GB', { timeZone: zones[i], weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false })
                    .formatToParts(new Date());
                var get = function (type) { return parts.filter(function (p) { return p.type === type; })[0].value; };
                var day = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(get('weekday'));
                if (day >= 0) return { day: day, minutes: (parseInt(get('hour'), 10) % 24) * 60 + parseInt(get('minute'), 10) };
            } catch (e) {   }
        }
        return null;
    }

    function update() {
        var label = document.getElementById('time');
        var rows = document.querySelectorAll('.work_time .all_time li');
        var now = kyivNow();
        if (!label || rows.length !== 7 || !now) return;

        var week = Array.prototype.map.call(rows, function (li) {
            var m = /(\d{1,2}):(\d{2})\s*[-–—]\s*(\d{1,2}):(\d{2})/.exec(li.textContent);
            return m ? { open: +m[1] * 60 + +m[2], close: +m[3] * 60 + +m[4], from: +m[1] + ':' + m[2], to: +m[3] + ':' + m[4] } : null;
        });
        var today = week[now.day], text;
        if (today && now.minutes >= today.open && now.minutes < today.close) {
            text = 'Сьогодні до ' + today.to;
        } else if (today && now.minutes < today.open) {
            text = 'Сьогодні з ' + today.from + ' до ' + today.to;
        } else {
            for (var i = 1; i <= 7 && !text; i++) {
                var next = week[(now.day + i) % 7];
                if (next) text = 'Зачинено до ' + (i === 1 ? 'завтра' : DAYS[(now.day + i) % 7]) + ', ' + next.from;
            }
        }
        if (text && label.textContent !== text) label.textContent = text;
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update); else update();

    window.addEventListener('load', function () { setTimeout(update, 800); });
    setInterval(update, 60000);
})();
