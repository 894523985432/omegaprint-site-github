(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fine = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

    function counters() {
        var items = document.querySelectorAll('[data-rd-count]');
        if (!items.length || reduceMotion || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                io.unobserve(entry.target);
                var el = entry.target, target = parseInt(el.getAttribute('data-rd-count'), 10) || 0;
                var suffix = el.getAttribute('data-rd-suffix') || '', start = null, dur = 1600 + Math.min(target, 50000) / 60;
                function step(t) {
                    if (start === null) start = t;
                    var p = Math.min((t - start) / dur, 1), eased = 1 - Math.pow(1 - p, 4);
                    el.textContent = fmt(Math.round(target * eased)) + suffix;
                    if (p < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        }, { threshold: 0.4 });
        items.forEach(function (el) { io.observe(el); });
    }

    function tilt() {
        var stage = document.querySelector('[data-rd-tilt]');
        if (!stage || reduceMotion || !fine) return;
        var card = stage.closest('.rd-hero__card'), raf = 0;
        card.addEventListener('mousemove', function (e) {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(function () {
                var r = card.getBoundingClientRect();
                var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
                stage.style.transform = 'rotateY(' + (x * 10).toFixed(2) + 'deg) rotateX(' + (-y * 8).toFixed(2) + 'deg)';
            });
        });
        card.addEventListener('mouseleave', function () { stage.style.transform = ''; });
    }

    function spotlight() {
        if (!fine) return;
        document.addEventListener('mousemove', function (e) {
            var card = e.target.closest && e.target.closest('#services .block_services, .rd-step');
            if (!card) return;
            var r = card.getBoundingClientRect();
            card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            card.style.setProperty('--my', (e.clientY - r.top) + 'px');
        }, { passive: true });
    }

    function marquee() {
        var track = document.querySelector('.rd-marquee__track');
        if (!track || track.dataset.cloned) return;
        track.dataset.cloned = '1';
        Array.prototype.slice.call(track.children).forEach(function (li) {
            var copy = li.cloneNode(true);
            copy.setAttribute('aria-hidden', 'true');
            copy.setAttribute('data-cms-skip', '');
            track.appendChild(copy);
        });
    }

    function reveal() {
        if (reduceMotion || !('IntersectionObserver' in window)) return;
        var els = document.querySelectorAll('.rd-head, .rd-step, .rd-cta, .rd-marquee');
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.1 });
        var vh = window.innerHeight;
        els.forEach(function (el, i) {
            if (el.getBoundingClientRect().top < vh * 0.92) return;
            el.classList.add('rd-reveal');
            if (el.classList.contains('rd-step')) el.style.transitionDelay = (i % 4) * 90 + 'ms';
            io.observe(el);
        });
    }

    function hours() {
        var time = document.getElementById('time');
        var chip = document.querySelector('[data-rd-hours]');
        function sync() {
            if (!time) return;
            var label = time.textContent.trim(), closed = /^Зачинено|^Closed|^Закрыто/i.test(label);
            var box = time.closest('.work_time');
            if (box) box.classList.toggle('is-closed', closed);
            if (chip) {
                chip.textContent = label;
                chip.parentNode.classList.toggle('is-closed', closed);
            }
        }
        sync();
        if (time && 'MutationObserver' in window) new MutationObserver(sync).observe(time, { childList: true, characterData: true, subtree: true });
    }

    function pauseOffscreen() {
        if (!('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { entry.target.classList.toggle('rd-paused', !entry.isIntersecting); });
        });
        document.querySelectorAll('.rd-hero, .rd-cta-wrap').forEach(function (el) { io.observe(el); });
    }

    function searchToggle() {
        var col = document.querySelector('.rd-search-col');
        if (!col) return;
        var btn = col.querySelector('.rd-search-toggle'), input = col.querySelector('input[type=text]');
        function open() {
            var ctl = document.querySelector('#main_menu .control_block');
            col.style.setProperty('--rd-search-right', ((ctl ? ctl.offsetWidth : 0) + 24) + 'px');
            col.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            input.focus();
        }
        function close() {
            col.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        }
        btn.addEventListener('click', open);
        input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); btn.focus(); } });
        document.addEventListener('click', function (e) { if (!col.contains(e.target)) close(); });
    }

    ready(function () {
        searchToggle();
        pauseOffscreen();
        counters();
        tilt();
        spotlight();
        marquee();
        reveal();
        hours();
    });
})();
