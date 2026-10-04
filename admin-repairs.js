(function () {
    'use strict';

    var A = window.AdminCore;
    var $ = A.$, $$ = A.$$, esc = A.esc, money = A.money, toast = A.toast;
    var API = 'api/repairs.php';
    var PALETTE = ['#008aff', '#6366f1', '#8b5cf6', '#f59e0b', '#f97316', '#ef4444', '#10b981', '#16a34a', '#0ea5e9', '#6b7280'];

    var state = {
        statuses: [],
        filter: '',
        q: '',
        page: 1,
        order: null,
        items: [],
        dicts: {},
        loaded: false
    };

    function get(params) {
        return A.getJSON(API + '?' + new URLSearchParams(params));
    }

    function post(action, data) {
        return A.apiPost(API + '?action=' + action, data instanceof FormData ? data : A.formData(data));
    }

    function smsMessage(sms) {
        if (!sms) return '';
        return {
            sent: 'Статус змінено. Клієнту надіслано SMS з проханням про відгук',
            not_configured: 'Статус змінено. SMS не налаштовано — текст із посиланням є у вкладці «SMS»',
            failed: 'Статус змінено, але SMS не надіслано (див. вкладку «SMS»)',
            bad_phone: 'Статус змінено. SMS не надіслано: некоректний номер телефону'
        }[sms.status] || 'Статус змінено';
    }
    window.AdminSmsMessage = smsMessage;

    function num(id) { return '№' + String(id).padStart(5, '0'); }

    function dateTime(utc, withTime) {
        if (!utc) return '';
        var d = new Date(utc.replace(' ', 'T') + 'Z');
        var opts = { day: '2-digit', month: '2-digit', year: 'numeric' };
        if (withTime !== false) { opts.hour = '2-digit'; opts.minute = '2-digit'; }
        return d.toLocaleString('uk-UA', opts);
    }

    function localDate(ymd) {
        if (!ymd) return '';
        var p = ymd.split('-');
        return p[2] + '.' + p[1] + '.' + p[0];
    }

    function isOverdue(order) {
        if (!order.due_date || order.closed_at) return false;
        return order.due_date < new Date().toISOString().slice(0, 10);
    }

    function statusById(id) {
        return state.statuses.filter(function (s) { return s.id === id; })[0] || null;
    }

    function textOn(hex) {
        var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
        return (r * 299 + g * 587 + b * 114) / 1000 > 160 ? '#111827' : '#ffffff';
    }

    function statusBadge(status) {
        if (!status) return '<span class="badge">Без статусу</span>';
        return '<span class="status_badge" style="background:' + status.color + ';color:' + textOn(status.color) + '">' + esc(status.name) + '</span>';
    }

    function statusOptions(selected, withEmpty, withAdd) {

        function group(label, closed) {
            var list = state.statuses.filter(function (s) { return !!s.is_closed === closed; });
            return list.length ? '<optgroup label="' + label + '">' + list.map(function (s) {
                return '<option value="' + s.id + '" style="background:' + esc(s.color) + ';color:' + textOn(s.color) + '"' +
                    (s.id === selected ? ' selected' : '') + '>' + esc(s.name) + '</option>';
            }).join('') + '</optgroup>' : '';
        }
        return (withEmpty ? '<option value="" class="status_plain">Без статусу</option>' : '') + group('В роботі', false) + group('Закриті', true) +
            (withAdd ? '<option value="__new" class="status_plain">+ Новий статус…</option>' : '');
    }

    function paintSelect(select) {
        var s = statusById(+select.value);
        select.style.background = s ? s.color : '';
        select.style.color = s ? textOn(s.color) : '';
        A.statusMenu(select);
    }

    function device(o) {
        return [o.device_type, o.device_brand, o.device_model].filter(Boolean).join(' ');
    }

    function loadStatuses() {
        return get({ action: 'statuses' }).then(function (res) {
            state.statuses = res.statuses || [];
            renderStatusList();
            renderChips();
            $('#noStatusesNotice').hidden = state.statuses.length > 0;
        });
    }

    function renderStatusList() {
        $('#statusList').innerHTML = state.statuses.map(function (s) {
            return '<li class="status_row">' +
                '<span class="swatch" style="background:' + s.color + '"></span>' +
                '<span class="name">' + esc(s.name) + '</span>' +
                (s.is_default ? '<span class="badge pop">За замовчуванням</span>' : '') +
                (s.is_closed ? '<span class="badge">Закриває</span>' : '') +
                (s.request_review ? '<span class="badge ok">Просить відгук (SMS)</span>' : '') +
                '<span class="count">' + s.orders + ' замовл.</span>' +
                '<button type="button" class="btn small" data-status-edit="' + s.id + '">Змінити</button>' +
                '<button type="button" class="btn small danger" data-status-del="' + s.id + '">Видалити</button>' +
            '</li>';
        }).join('') || '<li class="muted">Статусів ще немає. Натисніть «+ Додати статус».</li>';
    }

    var statusTarget = null;

    function openStatusDialog(s, target) {
        statusTarget = target || null;
        var form = $('#statusForm'), f = form.elements;
        form.reset();
        A.clearErrors(form);
        $('#statusDialogTitle').textContent = s ? 'Редагувати статус' : 'Новий статус';
        f.id.value = s ? s.id : '';
        f.name.value = s ? s.name : '';
        f.color.value = s ? s.color : PALETTE[state.statuses.length % PALETTE.length];
        f.sort.value = s ? s.sort : '';
        f.is_default.checked = s ? s.is_default : state.statuses.length === 0;
        f.is_closed.checked = s ? s.is_closed : false;
        f.request_review.checked = s ? s.request_review : false;
        markSwatch();
        $('#statusDialog').showModal();
        f.name.focus();
    }

    function markSwatch() {
        var value = $('#statusForm').elements.color.value.toLowerCase();
        $$('#statusSwatches button').forEach(function (b) { b.classList.toggle('active', b.dataset.color === value); });
    }

    $('#statusSwatches').innerHTML = PALETTE.map(function (c) {
        return '<button type="button" data-color="' + c + '" style="background:' + c + '" aria-label="Колір ' + c + '"></button>';
    }).join('');
    $('#statusSwatches').addEventListener('click', function (e) {
        if (!e.target.dataset.color) return;
        $('#statusForm').elements.color.value = e.target.dataset.color;
        markSwatch();
    });
    $('#statusForm').elements.color.addEventListener('input', markSwatch);
    $('#statusDialog').addEventListener('close', function () {

        if (statusTarget === 'header' && state.order && state.order.id) renderOrderHeader();
        if (statusTarget === 'form') {
            var sel = $('#repairForm').elements.status_id;
            if (sel.value === '__new') sel.selectedIndex = 0;
        }
        statusTarget = null;
    });

    $('#addStatusBtn').addEventListener('click', function () { openStatusDialog(null); });
    $('#goStatusesBtn').addEventListener('click', function () { A.showTab('statuses'); openStatusDialog(null); });
    $('#statusList').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var s = statusById(+(btn.dataset.statusEdit || btn.dataset.statusDel));
        if (!s) return;
        if (btn.dataset.statusEdit) openStatusDialog(s);
        if (btn.dataset.statusDel) {
            if (!confirm('Видалити статус «' + s.name + '»?')) return;
            post('status_delete', { id: s.id }).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
                toast('Статус видалено');
                loadStatuses();
            });
        }
    });

    $('#statusForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        A.clearErrors(form);
        post('status_save', new FormData(form)).then(function (res) {
            if (!res.ok) return A.showErrors(form, res, '#statusError');
            $('#statusDialog').close();
            toast('Статус збережено');
            var target = statusTarget;
            statusTarget = null;
            loadStatuses().then(function () {
                if (state.order && state.order.id) renderOrderHeader();
                if (state.order && !state.order.id) {
                    var sel = $('#repairForm').elements.status_id;
                    sel.innerHTML = statusOptions(target === 'form' ? res.id : +sel.value || null, false, true);
                }
                if (target === 'header') {
                    $('#repairStatus').value = res.id;
                    $('#repairStatus').dispatchEvent(new Event('change'));
                }
                loadOrders();
            });
        });
    });

    function renderChips() {
        var chips = [
            { key: '', label: 'Усі' },
            { key: 'open', label: 'Відкриті' },
            { key: 'closed', label: 'Закриті' }
        ].map(function (c) {
            return '<button type="button" class="chip' + (state.filter === c.key ? ' active' : '') + '" data-filter="' + c.key + '">' + c.label + '</button>';
        }).concat(state.statuses.map(function (s) {
            return '<button type="button" class="chip' + (state.filter === String(s.id) ? ' active' : '') + '" data-filter="' + s.id + '">' +
                '<span class="dot" style="background:' + s.color + '"></span>' + esc(s.name) + ' <span class="count">' + s.orders + '</span></button>';
        }));
        $('#statusChips').innerHTML = chips.join('');
    }

    $('#statusChips').addEventListener('click', function (e) {
        var chip = e.target.closest('[data-filter]');
        if (!chip) return;
        state.filter = chip.dataset.filter;
        state.page = 1;
        renderChips();
        loadOrders();
    });

    function loadOrders() {
        var params = { action: 'orders', page: state.page };
        if (state.filter) params.status = state.filter;
        if (state.q) params.q = state.q;
        return get(params).then(function (res) {
            var orders = res.orders || [];
            $('#repairRows').innerHTML = orders.map(function (o) {
                return '<tr class="clickable' + (isOverdue(o) ? ' overdue' : '') + '" data-open="' + o.id + '" tabindex="0">' +
                    '<td><strong>' + num(o.id) + '</strong>' + (o.source === 'site' ? '<span class="sku">з сайту</span>' : o.source === 'telegram' ? '<span class="sku">з Telegram</span>' : '') + '</td>' +
                    '<td>' + dateTime(o.created_at) + '</td>' +
                    '<td>' + esc(o.client_name || '—') + (o.client_phone ? '<span class="sku">' + esc(o.client_phone) + '</span>' : '') + '</td>' +
                    '<td>' + esc(device(o) || '—') + (o.serial ? '<span class="sku">S/N ' + esc(o.serial) + '</span>' : '') + '</td>' +
                    '<td><select class="row_status" data-status-for="' + o.id + '" aria-label="Статус ' + num(o.id) + '">' + statusOptions(o.status_id, !o.status_id) + '</select></td>' +
                    '<td class="num">' + (o.total ? money(o.total) : (o.estimate ? '≈ ' + money(o.estimate) : '—')) + '</td>' +
                    '<td>' + (o.due_date ? localDate(o.due_date) : '—') + '</td>' +
                '</tr>';
            }).join('') || '<tr><td colspan="7" class="muted">Замовлень не знайдено.</td></tr>';
            $$('#repairRows .row_status').forEach(paintSelect);

            var pager = '';
            for (var i = 1; res.pages > 1 && i <= res.pages; i++) {
                pager += '<button type="button" data-page="' + i + '"' + (i === res.page ? ' aria-current="page"' : '') + '>' + i + '</button>';
            }
            $('#repairPager').innerHTML = pager;
        });
    }

    var searchTimer;
    $('#repairSearch').addEventListener('input', function () {
        var value = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.q = value; state.page = 1; loadOrders(); }, 300);
    });
    $('#repairPager').addEventListener('click', function (e) {
        if (e.target.dataset.page) { state.page = +e.target.dataset.page; loadOrders(); }
    });

    $('#repairRows').addEventListener('click', function (e) {
        if (e.target.closest('select, .status_btn')) return;
        var row = e.target.closest('[data-open]');
        if (row) location.hash = 'order-' + row.dataset.open;
    });
    $('#repairRows').addEventListener('keydown', function (e) {
        var row = e.target.closest('[data-open]');
        if (row && e.key === 'Enter' && e.target === row) location.hash = 'order-' + row.dataset.open;
    });

    $('#repairRows').addEventListener('change', function (e) {
        var select = e.target.closest('[data-status-for]');
        if (!select) return;
        paintSelect(select);
        post('order_status', { id: select.dataset.statusFor, status_id: select.value }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося змінити статус.', true);
            toast(smsMessage(res.sms) || 'Статус змінено', res.sms && res.sms.status !== 'sent' && res.sms.status !== 'not_configured');
            loadStatuses();
            if (state.filter) loadOrders();
        });
    });

    $('#addRepairBtn').addEventListener('click', function () { location.hash = 'order-new'; });

    function showList() {
        state.order = null;
        $('#repairView').hidden = true;
        $('#repairList').hidden = false;
        loadOrders();
    }

    function openOrder(id) {
        A.showTab('repairs');
        $('#repairList').hidden = true;
        $('#repairView').hidden = false;
        window.scrollTo(0, 0);
        if (id === 'new') {
            state.order = { id: null };
            state.items = [];
            fillForm({});
            renderOrderHeader();
            $('#repairItemsCard').hidden = true;
            $('#repairChat').hidden = true;
            $('#repairForm').elements.client_name.focus();
            return;
        }
        return get({ action: 'order', id: id }).then(function (res) {
            if (!res.ok) { toast(res.message || 'Замовлення не знайдено.', true); location.hash = ''; return; }
            state.order = res.order;
            state.items = res.items;
            fillForm(res.order);
            renderOrderHeader();
            renderItems();
            renderChat(res.comments);
            $('#repairItemsCard').hidden = false;
            $('#repairChat').hidden = false;
        });
    }

    function reloadOrder() {
        if (!state.order || !state.order.id) return;
        return get({ action: 'order', id: state.order.id }).then(function (res) {
            if (!res.ok) return;
            state.order = res.order;
            state.items = res.items;
            renderOrderHeader();
            renderItems();
            renderChat(res.comments);
        });
    }

    function fillForm(o) {
        var form = $('#repairForm'), f = form.elements;
        form.reset();
        A.clearErrors(form);
        ['id', 'client_name', 'client_phone', 'client_company', 'edrpou', 'device_type', 'device_brand', 'device_model', 'serial',
         'malfunction', 'complectation', 'appearance', 'due_date'].forEach(function (k) { f[k].value = o[k] || ''; });
        window.PhoneMask.refresh(f.client_phone);
        $$('input[name=messenger]', form).forEach(function (r) { r.checked = r.value === (o.messenger || ''); });
        f.estimate.value = o.estimate != null ? o.estimate : '';
        f.prepayment.value = o.prepayment ? o.prepayment : '';

        var def = state.statuses.filter(function (s) { return s.is_default; })[0] || state.statuses[0];
        f.status_id.innerHTML = statusOptions(def ? def.id : null, !state.statuses.length, true);
        $('#newStatusBox').hidden = !!o.id;
        $('#repairSave').textContent = o.id ? 'Зберегти' : 'Створити замовлення';
    }

    function renderOrderHeader() {
        var o = state.order;
        var isNew = !o.id;
        $('#repairTitle').textContent = isNew ? 'Нове замовлення' : 'Замовлення ' + num(o.id);
        $('#repairSource').hidden = isNew || (o.source !== 'site' && o.source !== 'telegram');
        $('#repairSource').textContent = o.source === 'telegram' ? 'з Telegram-бота' : 'з сайту';
        $('#repairStatusBox').hidden = isNew;
        $('#repairPrint').hidden = isNew;
        $('#repairDelete').hidden = isNew;
        $('#repairMeta').textContent = isNew ? '' :
            'Створено ' + dateTime(o.created_at) + ' · Змінено ' + dateTime(o.updated_at) +
            (o.closed_at ? ' · Закрито ' + dateTime(o.closed_at) : '') +
            (isOverdue(o) ? ' · Термін минув ' + localDate(o.due_date) : '');
        if (!isNew) {
            var select = $('#repairStatus');
            select.innerHTML = statusOptions(o.status_id, !o.status_id, true);
            select.value = o.status_id || '';
            paintSelect(select);
        }
    }

    window.addEventListener('hashchange', route);
    function route() {
        var m = location.hash.match(/^#order-(new|\d+)$/);
        if (m) openOrder(m[1]); else if (state.loaded) showList();
    }

    $('#repairBack').addEventListener('click', function () { location.hash = ''; });

    $('#repairForm').elements.status_id.addEventListener('change', function () {
        if (this.value === '__new') openStatusDialog(null, 'form');
    });

    $('#repairStatus').addEventListener('change', function () {
        var select = this;
        if (select.value === '__new') return openStatusDialog(null, 'header');
        paintSelect(select);
        post('order_status', { id: state.order.id, status_id: select.value }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося змінити статус.', true);
            toast(smsMessage(res.sms) || 'Статус змінено', res.sms && res.sms.status !== 'sent' && res.sms.status !== 'not_configured');
            loadStatuses();
            reloadOrder();
        });
    });

    $('#repairDelete').addEventListener('click', function () {
        if (!confirm('Видалити замовлення ' + num(state.order.id) + ' разом з роботами і коментарями? Це не можна скасувати.')) return;
        post('order_delete', { id: state.order.id }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
            toast('Замовлення видалено');
            loadStatuses();
            location.hash = '';
        });
    });

    $('#repairForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        A.clearErrors(form);
        var fd = new FormData(form);
        if (form.elements.id.value) fd.delete('status_id');
        var btn = $('#repairSave');
        btn.disabled = true;
        post('order_save', fd).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return A.showErrors(form, res, '#repairError');
            toast(form.elements.id.value ? 'Збережено' : 'Замовлення створено');
            loadStatuses();
            if (!form.elements.id.value) location.hash = 'order-' + res.id;
            else reloadOrder();
            loadDicts();
        });
    });

    function renderItems() {
        var works = 0, parts = 0;
        $('#itemRows').innerHTML = state.items.map(function (i) {
            var sum = i.qty * i.price;
            if (i.kind === 'part') parts += sum; else works += sum;
            return '<tr>' +
                '<td><span class="badge' + (i.kind === 'part' ? '' : ' pop') + '">' + (i.kind === 'part' ? 'Запчастина' : 'Робота') + '</span></td>' +
                '<td>' + esc(i.name) + '</td>' +
                '<td>' + esc(i.code || '') + '</td>' +
                '<td class="num">' + formatQty(i.qty) + '</td>' +
                '<td class="num">' + money(i.price) + '</td>' +
                '<td class="num"><strong>' + money(sum) + '</strong></td>' +
                '<td class="actions"><button type="button" class="btn small" data-item-edit="' + i.id + '">Змінити</button> ' +
                    '<button type="button" class="btn small danger" data-item-del="' + i.id + '" aria-label="Видалити">×</button></td>' +
            '</tr>';
        }).join('') || '<tr><td colspan="7" class="muted">Робіт і запчастин ще немає.</td></tr>';

        var total = works + parts;
        var prepay = state.order.prepayment || 0;
        $('#repairTotals').innerHTML =
            '<dt>Роботи</dt><dd>' + money(works) + ' грн</dd>' +
            '<dt>Запчастини</dt><dd>' + money(parts) + ' грн</dd>' +
            '<dt class="total">Разом</dt><dd class="total">' + money(total) + ' грн</dd>' +
            (prepay ? '<dt>Передоплата</dt><dd>− ' + money(prepay) + ' грн</dd>' +
                      '<dt class="total">До сплати</dt><dd class="total">' + money(Math.max(0, total - prepay)) + ' грн</dd>' : '') +
            (state.order.estimate != null ? '<dt class="muted">Орієнтовна вартість</dt><dd class="muted">' + money(state.order.estimate) + ' грн</dd>' : '');
    }

    function formatQty(q) {
        return Number(q) % 1 ? String(q).replace('.', ',') : String(q);
    }

    function resetItemForm() {
        var form = $('#itemForm');
        form.reset();
        form.elements.id.value = '';
        form.elements.qty.value = '1';
        form.elements.name.dataset.combo = form.elements.kind.value;
        $('#itemSubmit').textContent = 'Додати';
        $('#itemCancel').hidden = true;
        $('#itemError').textContent = '';
    }

    $('#itemRows').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var item = state.items.filter(function (i) { return i.id === +(btn.dataset.itemEdit || btn.dataset.itemDel); })[0];
        if (!item) return;
        if (btn.dataset.itemEdit) {
            var f = $('#itemForm').elements;
            f.id.value = item.id;
            f.kind.value = item.kind;
            f.name.value = item.name;
            f.code.value = item.code || '';
            f.name.dataset.combo = item.kind;
            f.qty.value = formatQty(item.qty);
            f.price.value = item.price;
            $('#itemSubmit').textContent = 'Зберегти';
            $('#itemCancel').hidden = false;
            f.name.focus();
        }
        if (btn.dataset.itemDel) {
            if (!confirm('Видалити «' + item.name + '»?')) return;
            post('item_delete', { id: item.id }).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
                reloadOrder();
            });
        }
    });
    $('#itemCancel').addEventListener('click', resetItemForm);

    $('#itemForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(this);
        fd.append('order_id', state.order.id);
        post('item_save', fd).then(function (res) {
            if (!res.ok) {
                var errors = res.errors || {};
                $('#itemError').textContent = errors.name || errors.qty || errors.price || res.message || 'Не вдалося зберегти.';
                return;
            }
            resetItemForm();
            $('#itemForm').elements.name.focus();
            reloadOrder();
            loadDicts();
        });
    });

    function renderChat(comments) {
        var log = $('#chatLog');
        log.innerHTML = comments.map(function (c) {
            if (c.kind === 'system') {
                return '<div class="chat_system">' + esc(c.text) + ' <span>' + dateTime(c.created_at) + '</span></div>';
            }
            return '<div class="chat_msg' + (c.mine ? ' mine' : '') + '">' +
                '<div class="chat_meta">' + esc(c.author || 'Видалений користувач') + ' · ' + dateTime(c.created_at) +
                    (c.mine ? ' <button type="button" class="link" data-comment-del="' + c.id + '">видалити</button>' : '') + '</div>' +
                '<div class="chat_text">' + esc(c.text).replace(/\n/g, '<br>') + '</div>' +
            '</div>';
        }).join('') || '<p class="muted">Коментарів ще немає.</p>';
        log.scrollTop = log.scrollHeight;
    }

    $('#commentForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        var text = form.elements.text.value.trim();
        if (!text) return;
        var btn = form.querySelector('button');
        btn.disabled = true;
        post('comment_add', { order_id: state.order.id, text: text }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return toast((res.errors && res.errors.text) || res.message || 'Не вдалося надіслати.', true);
            form.reset();
            reloadOrder();
        });
    });
    $('#commentForm').elements.text.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) $('#commentForm').requestSubmit();
    });
    $('#chatLog').addEventListener('click', function (e) {
        var id = e.target.dataset.commentDel;
        if (!id || !confirm('Видалити коментар?')) return;
        post('comment_delete', { id: id }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
            reloadOrder();
        });
    });

    function loadDicts() {
        return get({ action: 'dicts' }).then(function (res) {
            state.dicts = res.dicts || {};
        });
    }

    var DICT_TITLES = {
        device_type: 'тип пристрою', device_brand: 'виробника', device_model: 'модель', serial: 'серійний номер', complectation: 'комплектацію',
        appearance: 'опис стану', work: 'роботу', part: 'запчастину'
    };

    function combo(input) {
        var wrap = document.createElement('div');
        wrap.className = 'combo';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'combo_toggle';
        toggle.tabIndex = -1;
        toggle.setAttribute('aria-label', 'Показати список');
        wrap.appendChild(toggle);
        var list = document.createElement('ul');
        list.className = 'combo_list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        wrap.appendChild(list);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        var multi = input.hasAttribute('data-multi');
        var active = -1;

        function kind() { return input.dataset.combo; }
        function parts() { return input.value.split(',').map(function (x) { return x.trim(); }); }
        function term() { return multi ? parts().pop() : input.value.trim(); }

        function render() {
            var t = term().toLowerCase();
            var taken = multi ? parts().slice(0, -1).map(function (x) { return x.toLowerCase(); }) : [];
            var entries = (state.dicts[kind()] || []).filter(function (d) {
                return d.value.toLowerCase().indexOf(t) !== -1 && taken.indexOf(d.value.toLowerCase()) === -1;
            });
            var exact = (state.dicts[kind()] || []).some(function (d) { return d.value.toLowerCase() === t; });
            var html = entries.slice(0, 60).map(function (d, i) {
                return '<li role="option" data-i="' + i + '" data-value="' + esc(d.value) + '">' +
                    '<span class="v">' + esc(d.value) + '</span>' +
                    (d.price != null ? '<span class="p">' + money(d.price) + ' грн</span>' : '') +
                    '<button type="button" class="x" data-del="' + d.id + '" title="Прибрати зі списку" aria-label="Прибрати зі списку">×</button></li>';
            }).join('');
            if (t && !exact) {
                html += '<li role="option" class="add" data-add="1">+ Додати «' + esc(term()) + '» у список</li>';
            }
            if (!html) html = '<li class="empty">Список порожній — введіть значення, щоб додати</li>';
            list.innerHTML = html;
            active = -1;
        }

        function open() { render(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); wrap.classList.add('open'); }
        function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); wrap.classList.remove('open'); }

        function choose(value) {
            if (multi) {
                var p = parts();
                p[p.length - 1] = value;
                input.value = p.filter(Boolean).join(', ') + ', ';
            } else {
                input.value = value;
            }
            var entry = (state.dicts[kind()] || []).filter(function (d) { return d.value === value; })[0];
            input.dispatchEvent(new CustomEvent('combo:select', { detail: entry || { value: value } }));
            if (multi) { render(); input.focus(); } else close();
        }

        function add() {
            var value = term();
            if (!value) return;
            var priceInput = input.form && input.form.elements.price;
            post('dict_add', { kind: kind(), value: value, price: priceInput ? priceInput.value : '' }).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося додати.', true);
                toast('«' + value + '» додано до списку');
                loadDicts().then(function () { choose(value); });
            });
        }

        input.addEventListener('focus', open);
        input.addEventListener('input', open);
        toggle.addEventListener('click', function () { if (list.hidden) { input.focus(); open(); } else close(); });
        input.addEventListener('keydown', function (e) {
            var items = $$('li[role=option]', list);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) open();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach(function (li, i) { li.classList.toggle('active', i === active); });
                if (items[active]) items[active].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && !list.hidden && items[active]) {
                e.preventDefault();
                if (items[active].dataset.add) add(); else choose(items[active].dataset.value);
            } else if (e.key === 'Escape' || e.key === 'Tab') {
                close();
            }
        });
        list.addEventListener('mousedown', function (e) { e.preventDefault(); });
        list.addEventListener('click', function (e) {
            var del = e.target.closest('[data-del]');
            if (del) {
                var li = del.closest('li');
                if (!confirm('Прибрати «' + li.dataset.value + '» зі списку? На вже створені замовлення це не вплине.')) return;
                post('dict_delete', { id: del.dataset.del }).then(function (res) {
                    if (!res.ok) return toast(res.message || 'Не вдалося прибрати.', true);
                    loadDicts().then(render);
                });
                return;
            }
            var li = e.target.closest('li[role=option]');
            if (!li) return;
            if (li.dataset.add) add(); else choose(li.dataset.value);
        });
        input.addEventListener('blur', function () {
            setTimeout(close, 120);
            if (multi) input.value = parts().filter(Boolean).join(', ');
        });
        input.setAttribute('title', 'Оберіть зі списку або введіть нове значення і натисніть «+ Додати» — ' + (DICT_TITLES[kind()] || ''));
    }

    $$('[data-combo]').forEach(combo);

    window.PhoneMask.attach($('#repairForm').elements.client_phone);

    $$('input[name=serial][data-combo]').forEach(function (input) {
        input.addEventListener('combo:select', function () {
            var f = input.form.elements;
            get({ action: 'device', serial: input.value }).then(function (res) {
                var d = res.ok && res.device;
                if (!d) return;
                ['device_type', 'device_brand', 'device_model'].forEach(function (name) {
                    if (d[name] && f[name]) f[name].value = d[name];
                });
                ['client_name', 'client_phone'].forEach(function (name) {
                    if (d[name] && f[name] && !f[name].value.replace(/[\s_+()\-]|^\+?380/g, '')) {
                        f[name].value = d[name];
                        f[name].dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
                toast('Дані пристрою підставлено із замовлення ' + num(d.order_id));
            });
        });
    });

    var itemForm = $('#itemForm');
    itemForm.elements.kind.addEventListener('change', function () {
        itemForm.elements.name.dataset.combo = this.value;
    });
    itemForm.elements.name.addEventListener('combo:select', function (e) {
        if (e.detail.price != null && !itemForm.elements.price.value) itemForm.elements.price.value = e.detail.price;
    });

    $('#repairPrint').addEventListener('click', function () {
        var w = window.open('nakladna.html?id=' + state.order.id, '_blank');
        if (!w) toast('Дозвольте спливаючі вікна, щоб відкрити накладну.', true);
    });

    document.addEventListener('admin:ready', function () {
        loadStatuses().then(function () {
            state.loaded = true;
            loadDicts();
            if (/^#order-/.test(location.hash)) route(); else loadOrders();
        });
    });
    document.addEventListener('admin:tab', function (e) {
        if (e.detail === 'repairs' && state.loaded && !state.order) loadOrders();
    });
})();
