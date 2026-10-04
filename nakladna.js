(function () {
    'use strict';

    var MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    var PART_ROWS = 6;

    var DEVICE_MATCH = {
        copier: /коп|ксерокс|бфп|мфу|багатофункц/i,
        printer: /принтер/i,
        shredder: /знищувач|шредер/i,
        plotter: /плот+ер/i
    };

    var id = parseInt(new URLSearchParams(location.search).get('id'), 10);
    var message = document.getElementById('message');

    function fill(name, value) {
        document.querySelectorAll('[data-f="' + name + '"]').forEach(function (el) { el.textContent = value || ''; });
    }

    function money(n) {
        return Number(n).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function qty(q) {
        return Number(q) % 1 ? String(q).replace('.', ',') : String(q);
    }

    function localDate(utc) {
        return utc ? new Date(utc.replace(' ', 'T') + 'Z') : null;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function shortName(p) {
        var first = (p.username || '').trim(), middle = (p.middle_name || '').trim(), last = (p.last_name || '').trim();
        if (!last) return [first, middle].filter(Boolean).join(' ');
        var initials = [first, middle].filter(Boolean).map(function (w) { return w.charAt(0).toUpperCase() + '.'; }).join(' ');
        return (initials ? initials + ' ' : '') + last;
    }

    function fail(html) {
        message.innerHTML = html;
    }

    if (!id) return fail('Не вказано номер замовлення. Відкрийте накладну з картки замовлення в <a href="admin.html">адмін-панелі</a>.');

    Promise.all([
        fetch('api/repairs.php?action=order&id=' + id, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json().then(function (d) { d.status = r.status; return d; }); }),
        fetch('auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }),

        fetch('api/account.php?action=profile', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return {}; })
    ]).then(function (res) {
        var data = res[0], me = res[1];
        if (me.user && res[2] && res[2].profile) me.user.profile = res[2].profile;
        if (data.status === 401 || data.status === 403) {
            return fail('Накладна доступна лише адміністраторам. <a href="admin.html">Увійдіть в адмін-панель</a> і відкрийте її знову.');
        }
        if (!data.ok) return fail(esc(data.message || 'Замовлення не знайдено.'));
        render(data.order, data.items, me.user);
        message.hidden = true;
        document.getElementById('front').hidden = false;
        document.getElementById('back').hidden = false;
    }).catch(function () {
        fail('Не вдалося завантажити дані. Перевірте, чи запущено сайт, і оновіть сторінку.');
    });

    function render(o, items, user) {
        var number = '№' + String(o.id).padStart(5, '0');
        document.title = 'Накладна ' + number + ' | OmegaPrint';
        document.getElementById('docTitle').textContent = 'Накладна ' + number;

        var created = localDate(o.created_at);
        fill('day', String(created.getDate()).padStart(2, '0'));
        fill('month', MONTHS[created.getMonth()]);
        fill('year', created.getFullYear());
        fill('number', number);
        fill('company', o.client_company);
        fill('edrpou', o.edrpou);
        fill('phone', o.client_phone);
        fill('person', o.client_name);
        fill('person_sign', o.client_name);
        fill('staff', user ? shortName(user.profile || { username: user.username }) : '');

        var model = [o.device_brand, o.device_model].filter(Boolean).join(' ');
        if (o.serial) model += (model ? ', ' : '') + 'S/N ' + o.serial;
        fill('model', model);

        var matched = false;
        Object.keys(DEVICE_MATCH).forEach(function (key) {
            if (o.device_type && DEVICE_MATCH[key].test(o.device_type)) {
                document.querySelector('[data-device="' + key + '"]').classList.add('on');
                matched = true;
            }
        });
        if (!matched && o.device_type) {
            document.querySelector('[data-device="other"]').classList.add('on');
            fill('other', o.device_type);
        }

        var parts = items.filter(function (i) { return i.kind !== 'part'; }).concat(items.filter(function (i) { return i.kind === 'part'; }));

        var table = document.getElementById('partsRows').closest('table');
        table.classList.toggle('dense', parts.length > PART_ROWS);
        table.classList.toggle('denser', parts.length > 10);
        var rows = parts.map(function (i) {
            return '<tr><td class="f" contenteditable>' + esc(i.name) + '</td><td class="f" contenteditable>' + esc(i.code || '') +
                '</td><td class="f" contenteditable>' + qty(i.qty) + '</td><td class="f" contenteditable>' + money(i.price) + '</td></tr>';
        });
        while (rows.length < PART_ROWS) {
            rows.push('<tr><td contenteditable></td><td contenteditable></td><td contenteditable></td><td contenteditable></td></tr>');
        }
        document.getElementById('partsRows').innerHTML = rows.join('');

        document.querySelectorAll('.pick').forEach(function (el) { el.classList.toggle('on', el.dataset.m === o.messenger); });

        var total = items.reduce(function (s, i) { return s + i.qty * i.price; }, 0);
        fill('total', total ? money(total) : '');
        var closed = localDate(o.closed_at);
        if (closed) {
            fill('out_day', String(closed.getDate()).padStart(2, '0'));
            fill('out_month', MONTHS[closed.getMonth()]);
            fill('out_year', closed.getFullYear());
        }

        document.querySelectorAll('.sheet .f').forEach(function (el) { el.setAttribute('contenteditable', ''); });
    }

    document.getElementById('devices').addEventListener('click', function (e) {
        var box = e.target.closest('.box');
        if (box && !e.target.closest('.f')) box.classList.toggle('on');
    });
    document.addEventListener('click', function (e) {
        var pick = e.target.closest('.pick');
        if (!pick) return;
        var was = pick.classList.contains('on');
        document.querySelectorAll('.pick').forEach(function (el) { el.classList.remove('on'); });
        if (!was) pick.classList.add('on');
    });

    document.getElementById('printBtn').addEventListener('click', function () {
        var side = document.querySelector('input[name=side]:checked').value;
        document.body.classList.toggle('only-front', side === 'front');
        document.body.classList.toggle('only-back', side === 'back');
        window.print();
    });
})();
