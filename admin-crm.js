(function () {
    'use strict';

    var A = window.AdminCore;
    var $ = A.$, $$ = A.$$, esc = A.esc, money = A.money, toast = A.toast;

    function get(url, params) { return A.getJSON(url + '?' + new URLSearchParams(params)); }
    function post(url, data) { return A.apiPost(url, A.formData(data)); }

    function dateTime(utc) {
        if (!utc) return '—';
        return new Date(utc.replace(' ', 'T') + 'Z').toLocaleString('uk-UA', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function dateOnly(utc) {
        return utc ? new Date(utc.replace(' ', 'T') + 'Z').toLocaleDateString('uk-UA') : '—';
    }
    function smsText(sms) {
        return window.AdminSmsMessage ? window.AdminSmsMessage(sms) : '';
    }
    function chips(el, items, active) {
        el.innerHTML = items.map(function (c) {
            return '<button type="button" class="chip' + (c.key === active ? ' active' : '') + '" data-key="' + c.key + '">' +
                esc(c.label) + (c.count != null ? ' <span class="count">' + c.count + '</span>' : '') + '</button>';
        }).join('');
    }

    var orders = { status: 'open', q: '', page: 1, statuses: {}, current: null };
    var STATUS_GROUPS = [['В роботі', ['new', 'confirmed', 'shipped']], ['Завершені', ['done', 'cancelled']]];
    var STATUS_COLORS = { new: '#008aff', confirmed: '#6366f1', shipped: '#f59e0b', done: '#16a34a', cancelled: '#94a3b8' };

    function loadOrders() {
        var params = { action: 'list', page: orders.page, status: orders.status };
        if (orders.q) params.q = orders.q;
        return get('api/orders.php', params).then(function (res) {
            if (!res.ok) return;
            orders.statuses = res.statuses;
            var counts = res.counts || {};
            var open = (counts.new || 0) + (counts.confirmed || 0) + (counts.shipped || 0);
            var badge = $('#ordersNewCount');
            badge.hidden = !counts.new;
            badge.textContent = counts.new || '';
            chips($('#orderChips'), [{ key: 'open', label: 'Активні', count: open }, { key: '', label: 'Усі' }]
                .concat(Object.keys(res.statuses).map(function (k) { return { key: k, label: res.statuses[k], count: counts[k] || 0 }; })), orders.status);
            $('#orderRows').innerHTML = res.orders.map(function (o) {
                return '<tr class="clickable" data-order="' + o.id + '">' +
                    '<td><strong>№' + o.id + '</strong></td>' +
                    '<td>' + dateTime(o.created_at) + '</td>' +
                    '<td>' + esc(o.name) + '<span class="sku">' + esc(o.phone) + '</span></td>' +
                    '<td>' + esc(o.delivery_text) + '<span class="sku">' + esc(o.payment_text) + '</span></td>' +
                    '<td class="num"><strong>' + money(o.total) + '</strong></td>' +
                    '<td><select class="row_status" data-order-status="' + o.id + '" aria-label="Статус замовлення №' + o.id + '">' +
                        STATUS_GROUPS.map(function (g) {
                            return '<optgroup label="' + g[0] + '">' + g[1].filter(function (k) { return res.statuses[k]; }).map(function (k) {

                                return '<option value="' + k + '" style="background:' + STATUS_COLORS[k] + ';color:#fff"' +
                                    (k === o.status ? ' selected' : '') + '>' + esc(res.statuses[k]) + '</option>';
                            }).join('') + '</optgroup>';
                        }).join('') + '</select></td>' +
                '</tr>';
            }).join('') || '<tr><td colspan="6" class="muted">Замовлень немає.</td></tr>';
            $$('#orderRows .row_status').forEach(paint);
            var pager = '';
            for (var i = 1; res.pages > 1 && i <= res.pages; i++) {
                pager += '<button type="button" data-page="' + i + '"' + (i === res.page ? ' aria-current="page"' : '') + '>' + i + '</button>';
            }
            $('#orderPager').innerHTML = pager;
        });
    }

    function paint(select) {
        var c = STATUS_COLORS[select.value] || '#6b7280';
        select.style.background = c;
        select.style.color = '#fff';
        A.statusMenu(select);
    }

    function setOrderStatus(id, status) {
        return post('api/orders.php?action=status', { id: id, status: status }).then(function (res) {
            if (!res.ok) { toast(res.message || 'Не вдалося змінити статус.', true); return res; }
            toast(res.sms ? smsText(res.sms).replace('Статус змінено', 'Замовлення виконано') : 'Статус змінено',
                res.sms && ['sent', 'not_configured'].indexOf(res.sms.status) === -1);
            loadOrders();
            loadSms();
            return res;
        });
    }

    $('#orderChips').addEventListener('click', function (e) {
        var chip = e.target.closest('[data-key]');
        if (!chip) return;
        orders.status = chip.dataset.key;
        orders.page = 1;
        loadOrders();
    });
    var orderTimer;
    $('#orderSearch').addEventListener('input', function () {
        var v = this.value.trim();
        clearTimeout(orderTimer);
        orderTimer = setTimeout(function () { orders.q = v; orders.page = 1; loadOrders(); }, 300);
    });
    $('#orderPager').addEventListener('click', function (e) {
        if (e.target.dataset.page) { orders.page = +e.target.dataset.page; loadOrders(); }
    });
    $('#orderRows').addEventListener('change', function (e) {
        var sel = e.target.closest('[data-order-status]');
        if (!sel) return;
        paint(sel);
        if (sel.value === 'done' && !confirm('Позначити замовлення №' + sel.dataset.orderStatus + ' виконаним? Клієнту буде надіслано SMS з проханням про відгук.')) {
            loadOrders();
            return;
        }
        setOrderStatus(sel.dataset.orderStatus, sel.value);
    });
    $('#orderRows').addEventListener('click', function (e) {
        if (e.target.closest('select, .status_btn')) return;
        var row = e.target.closest('[data-order]');
        if (row) openOrder(+row.dataset.order);
    });

    function openOrder(id) {
        get('api/orders.php', { action: 'get', id: id }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Замовлення не знайдено.', true);
            var o = res.order;
            orders.current = o;
            $('#orderDialogTitle').textContent = 'Замовлення №' + o.id + ' · ' + (orders.statuses[o.status] || o.status);
            $('#orderDetails').innerHTML =
                '<dl class="order_info">' +
                    '<dt>Дата</dt><dd>' + dateTime(o.created_at) + '</dd>' +
                    '<dt>Клієнт</dt><dd>' + esc(o.name) + (o.client_id ? ' <button type="button" class="link" data-client="' + o.client_id + '">картка клієнта</button>' : '') + '</dd>' +
                    '<dt>Телефон</dt><dd><a href="tel:' + esc(o.phone.replace(/[^\d+]/g, '')) + '">' + esc(o.phone) + '</a>' + (o.call_back ? ' · <b>просить передзвонити</b>' : '') + '</dd>' +
                    (o.email ? '<dt>Email</dt><dd><a href="mailto:' + esc(o.email) + '">' + esc(o.email) + '</a></dd>' : '') +
                    '<dt>Доставка</dt><dd>' + esc(o.delivery_text) + '</dd>' +
                    '<dt>Оплата</dt><dd>' + esc(o.payment_text) + '</dd>' +
                    (o.comment ? '<dt>Коментар</dt><dd>' + esc(o.comment) + '</dd>' : '') +
                    '<dt>SMS про відгук</dt><dd>' + (o.review_sms_at ? dateTime(o.review_sms_at) : 'ще не надсилалось') + '</dd>' +
                '</dl>' +
                '<table class="table items_table"><thead><tr><th>Товар</th><th class="num">К-сть</th><th class="num">Ціна</th><th class="num">Сума</th></tr></thead><tbody>' +
                res.items.map(function (i) {
                    return '<tr><td>' + esc(i.name) + (i.sku ? '<span class="sku">арт. ' + esc(i.sku) + '</span>' : '') + '</td><td class="num">' + i.qty +
                        '</td><td class="num">' + money(i.price) + '</td><td class="num"><strong>' + money(i.qty * i.price) + '</strong></td></tr>';
                }).join('') + '</tbody></table>' +
                '<p class="order_total">Разом: <strong>' + money(o.total) + ' грн</strong></p>' +
                (o.bonus_spent ? '<p class="order_total">Оплачено бонусами: ' + money(o.bonus_spent) + ' грн · До сплати: <strong>' + money(o.to_pay) + ' грн</strong></p>' : '') +
                (o.user_id ? '<p class="muted">Покупець зареєстрований' + (o.bonus_earned ? ' · нараховано бонусів: ' + money(o.bonus_earned)
                    : ' · бонуси нарахуються після статусу «Виконано»') + '</p>' : '');
            $('#orderSmsBtn').textContent = o.review_sms_at ? 'Надіслати SMS про відгук ще раз' : 'Надіслати SMS про відгук';
            $('#orderDialog').showModal();
        });
    }
    $('#orderDetails').addEventListener('click', function (e) {
        var b = e.target.closest('[data-client]');
        if (b) { $('#orderDialog').close(); A.showTab('clients'); openClient(+b.dataset.client); }
    });
    $('#orderSmsBtn').addEventListener('click', function () {
        var o = orders.current;
        if (!o || !confirm('Надіслати клієнту SMS з проханням залишити відгук?')) return;
        post('api/orders.php?action=review_sms', { type: 'shop', id: o.id }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося.', true);
            toast(smsText(res.sms).replace('Статус змінено. ', '').replace('Статус змінено, але ', ''),
                ['sent', 'not_configured'].indexOf(res.sms.status) === -1);
            $('#orderDialog').close();
            loadOrders();
            loadSms();
        });
    });

    var clients = { category: '', q: '' };
    var CAT_CLASS = { new: 'pop', rare: '', regular: 'ok', constant: 'ok', vip: 'vip', inactive: 'off' };

    function exportUrl() {
        var p = new URLSearchParams({ action: 'export' });
        if (clients.category) p.set('category', clients.category);
        if (clients.q) p.set('q', clients.q);
        return 'api/clients.php?' + p;
    }

    function loadClients() {
        var params = { action: 'list' };
        if (clients.category) params.category = clients.category;
        if (clients.q) params.q = clients.q;
        return get('api/clients.php', params).then(function (res) {
            if (!res.ok) return;
            var total = Object.keys(res.counts).reduce(function (s, k) { return s + res.counts[k]; }, 0);
            chips($('#clientChips'), [{ key: '', label: 'Усі', count: total }].concat(Object.keys(res.categories).map(function (k) {
                return { key: k, label: res.categories[k], count: res.counts[k] };
            })), clients.category);
            $('#clientExport').href = exportUrl();
            $('#clientRows').innerHTML = res.clients.map(function (c) {
                return '<tr class="clickable" data-client="' + c.id + '">' +
                    '<td><strong>' + esc(c.name || '—') + '</strong>' + (c.company ? '<span class="sku">' + esc(c.company) + '</span>' : '') + '</td>' +
                    '<td>' + esc(c.phone || '') + (c.email ? '<span class="sku">' + esc(c.email) + '</span>' : '') + '</td>' +
                    '<td><span class="badge ' + (CAT_CLASS[c.category] || '') + '">' + esc(c.category_name) + '</span></td>' +
                    '<td class="num">' + c.orders_total + ' / ' + c.orders_quarter + '</td>' +
                    '<td class="num">' + (c.avg_check ? money(c.avg_check) : '—') + '</td>' +
                    '<td class="num">' + (c.revenue ? money(c.revenue) : '—') + '</td>' +
                    '<td>' + dateOnly(c.last_order_at) + '</td>' +
                '</tr>';
            }).join('') || '<tr><td colspan="7" class="muted">Клієнтів ще немає — вони з\'являються автоматично із замовлень і ремонтів.</td></tr>';
        });
    }

    $('#clientChips').addEventListener('click', function (e) {
        var chip = e.target.closest('[data-key]');
        if (!chip) return;
        clients.category = chip.dataset.key;
        loadClients();
    });
    var clientTimer;
    $('#clientSearch').addEventListener('input', function () {
        var v = this.value.trim();
        clearTimeout(clientTimer);
        clientTimer = setTimeout(function () { clients.q = v; loadClients(); }, 300);
    });
    $('#clientRows').addEventListener('click', function (e) {
        var row = e.target.closest('[data-client]');
        if (row) openClient(+row.dataset.client);
    });

    function openClient(id) {
        get('api/clients.php', { action: 'get', id: id }).then(function (res) {
            if (!res.ok) return toast(res.message || 'Клієнта не знайдено.', true);
            var c = res.client, form = $('#clientForm'), f = form.elements;
            A.clearErrors(form);
            $('#clientDialogTitle').textContent = c.name || c.phone || 'Клієнт';
            $('#clientDelete').dataset.orders = res.shop_orders.length + res.repair_orders.length;
            $('#clientStats').innerHTML =
                '<div><span>Категорія</span><strong><span class="badge ' + (CAT_CLASS[c.category] || '') + '">' + esc(c.category_name) + '</span></strong></div>' +
                '<div><span>Телефон</span><strong>' + esc(c.phone || '—') + '</strong></div>' +
                '<div><span>Замовлень</span><strong>' + c.orders_total + ' <small>(за квартал: ' + c.orders_quarter + ')</small></strong></div>' +
                '<div><span>Середній чек</span><strong>' + (c.avg_check ? money(c.avg_check) + ' грн' : '—') + '</strong></div>' +
                '<div><span>Сума покупок</span><strong>' + (c.revenue ? money(c.revenue) + ' грн' : '—') + '</strong></div>' +
                '<div><span>Клієнт з</span><strong>' + dateOnly(c.created_at) + '</strong></div>';
            f.id.value = c.id;
            f.name.value = c.name;
            f.email.value = c.email;
            f.company.value = c.company;
            f.notes.value = c.notes;
            var rows = res.shop_orders.map(function (o) {
                return '<tr><td>Магазин №' + o.id + '</td><td>' + dateOnly(o.created_at) + '</td><td>' + esc(orders.statuses[o.status] || o.status) +
                    '</td><td class="num">' + money(o.total) + '</td></tr>';
            }).concat(res.repair_orders.map(function (o) {
                return '<tr><td><a href="#order-' + o.id + '" data-close-client>Ремонт №' + String(o.id).padStart(5, '0') + '</a><span class="sku">' +
                    esc([o.device_type, o.device_brand, o.device_model].filter(Boolean).join(' ')) + '</span></td><td>' + dateOnly(o.created_at) +
                    '</td><td>' + esc(o.status || 'без статусу') + '</td><td class="num">' + money(o.total) + '</td></tr>';
            }));
            $('#clientOrders').innerHTML = rows.length
                ? '<table class="table"><thead><tr><th>Замовлення</th><th>Дата</th><th>Статус</th><th class="num">Сума, грн</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>'
                : '<p class="muted">Замовлень немає.</p>';
            $('#clientDialog').showModal();
        });
    }
    $('#clientOrders').addEventListener('click', function (e) {
        if (e.target.closest('[data-close-client]')) $('#clientDialog').close();
    });
    $('#clientForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        A.clearErrors(form);
        A.apiPost('api/clients.php?action=save', new FormData(form)).then(function (res) {
            if (!res.ok) return A.showErrors(form, res, '#clientError');
            $('#clientDialog').close();
            toast('Клієнта збережено');
            loadClients();
        });
    });

    function loadBans() {
        get('api/clients.php', { action: 'bans' }).then(function (res) {
            if (!res.ok) return;
            $('#banBox').hidden = !res.bans.length;
            $('#banRows').innerHTML = res.bans.map(function (b) {
                return '<div class="ban_row"><div><strong>Мережа ' + esc(b.who.slice(0, 8)) + '</strong> · ' + dateOnly(b.created_at) + ' · ' + esc(b.reason) +
                    '<div class="muted">' + (b.accounts.length ? b.accounts.length + ' акаунт(ів): ' + b.accounts.map(function (a) { return esc(a.email); }).join(', ') : 'акаунтів немає') + '</div></div>' +
                    '<button type="button" class="btn" data-unban="' + esc(b.who) + '">Розблокувати</button></div>';
            }).join('');
        });
    }
    $('#banRows').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-unban]');
        if (!btn || !confirm('Розблокувати цю мережу? З неї знову можна буде реєструватись і оформлювати замовлення.')) return;
        A.apiPost('api/clients.php?action=unban', A.formData({ who: btn.dataset.unban })).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося.', true);
            toast('Мережу розблоковано');
            loadBans();
        });
    });
    document.addEventListener('admin:tab', function (e) { if (e.detail === 'clients') loadBans(); });

    $('#clientDelete').addEventListener('click', function () {
        var form = $('#clientForm'), n = +this.dataset.orders || 0;
        var name = form.elements.name.value || $('#clientDialogTitle').textContent;
        if (!confirm('Видалити клієнта «' + name + '» з бази?' + (n
                ? '\n\nЙого замовлень: ' + n + '. Вони залишаться в розділах «Замовлення» і «Ремонти» з іменем і телефоном, але без картки клієнта.'
                : '') + '\n\nЦю дію не можна скасувати.')) return;
        A.apiPost('api/clients.php?action=delete', A.formData({ id: form.elements.id.value })).then(function (res) {
            if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
            $('#clientDialog').close();
            toast('Клієнта видалено');
            loadClients();
        });
    });

    var reviews = { status: 'new' };

    function stars(n) {
        return '<span class="stars_small">' + '★★★★★'.slice(0, n) + '<span>' + '★★★★★'.slice(n) + '</span></span>';
    }

    function loadReviews() {
        return get('api/reviews.php', { action: 'list', status: reviews.status }).then(function (res) {
            if (!res.ok) return;
            var counts = res.counts || {};
            var badge = $('#reviewsNewCount');
            badge.hidden = !counts.new;
            badge.textContent = counts.new || '';
            chips($('#reviewChips'), Object.keys(res.statuses).map(function (k) {
                return { key: k, label: res.statuses[k], count: counts[k] || 0 };
            }).concat([{ key: '', label: 'Усі' }]), reviews.status);
            $('#reviewRows').innerHTML = res.reviews.map(function (r) {
                var order = r.order_type ? (r.order_type === 'shop' ? 'Замовлення магазину №' + r.order_id : 'Ремонт №' + String(r.order_id).padStart(5, '0')) : 'Без замовлення';
                return '<article class="review_admin" data-review="' + r.id + '">' +
                    '<header><strong>' + esc(r.name) + '</strong> ' + stars(r.stars) +
                        ' <span class="badge' + (r.order_type ? ' ok' : '') + '">' + esc(order) + '</span>' +
                        '<span class="muted">' + dateTime(r.created_at) + '</span></header>' +
                    '<p>' + esc(r.text).replace(/\n/g, '<br>') + '</p>' +
                    (r.pros ? '<p><b>Переваги:</b> ' + esc(r.pros) + '</p>' : '') +
                    (r.cons ? '<p><b>Недоліки:</b> ' + esc(r.cons) + '</p>' : '') +
                    '<div class="review_actions">' +
                        (r.status !== 'published' ? '<button type="button" class="btn small primary" data-set="published">Опублікувати</button>' : '') +
                        (r.status !== 'hidden' ? '<button type="button" class="btn small" data-set="hidden">Приховати</button>' : '') +
                        (r.client_id ? '<button type="button" class="btn small" data-client="' + r.client_id + '">Клієнт</button>' : '') +
                        '<button type="button" class="btn small danger" data-delete>Видалити</button>' +
                    '</div>' +
                '</article>';
            }).join('') || '<p class="muted">Відгуків немає.</p>';
        });
    }
    $('#reviewChips').addEventListener('click', function (e) {
        var chip = e.target.closest('[data-key]');
        if (!chip) return;
        reviews.status = chip.dataset.key;
        loadReviews();
    });
    $('#reviewRows').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        var card = e.target.closest('[data-review]');
        if (!btn || !card) return;
        var id = card.dataset.review;
        if (btn.dataset.client) { A.showTab('clients'); openClient(+btn.dataset.client); return; }
        if (btn.dataset.set) {
            post('api/reviews.php?action=status', { id: id, status: btn.dataset.set }).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося.', true);
                toast(btn.dataset.set === 'published' ? 'Відгук опубліковано на сайті' : 'Відгук приховано');
                loadReviews();
            });
        }
        if (btn.hasAttribute('data-delete') && confirm('Видалити відгук назавжди?')) {
            post('api/reviews.php?action=delete', { id: id }).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося.', true);
                toast('Відгук видалено');
                loadReviews();
            });
        }
    });

    var SMS_STATUS = {
        sent: ['ok', 'Надіслано'], failed: ['off', 'Помилка'], not_configured: ['warn', 'Не надіслано: SMS не налаштовано'],
        bad_phone: ['off', 'Некоректний номер']
    };
    function loadSms() {
        return get('api/orders.php', { action: 'sms_log' }).then(function (res) {
            if (!res.ok) return;
            var notice = $('#smsNotice');
            notice.hidden = res.configured;
            notice.innerHTML = 'Сервіс SMS ще не підключено: повідомлення записуються сюди, але не надсилаються. Текст можна скопіювати й надіслати вручну ' +
                '(наприклад, у Viber). Як підключити — у config.php (sms_token, sms_sender). Посилання у SMS ведуть на <b>' + esc(res.site_url) + '</b>' +
                ' — вкажіть справжню адресу сайту в site_url.';
            $('#smsRows').innerHTML = res.log.map(function (l) {
                var st = SMS_STATUS[l.status] || ['', l.status];
                return '<tr><td>' + dateTime(l.created_at) + '</td><td>' + esc(l.phone) + '</td>' +
                    '<td class="sms_text">' + esc(l.text) + ' <button type="button" class="link" data-copy="' + esc(l.text) + '">копіювати</button></td>' +
                    '<td>' + (l.order_type === 'shop' ? 'Магазин №' + l.order_id : l.order_type === 'repair' ? 'Ремонт №' + String(l.order_id).padStart(5, '0') : '—') + '</td>' +
                    '<td><span class="badge ' + st[0] + '" title="' + esc(l.response) + '">' + st[1] + '</span></td></tr>';
            }).join('') || '<tr><td colspan="5" class="muted">SMS ще не надсилались.</td></tr>';
        });
    }
    $('#smsRows').addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]');
        if (!b) return;
        (navigator.clipboard ? navigator.clipboard.writeText(b.dataset.copy) : Promise.reject()).then(function () {
            toast('Текст скопійовано');
        }, function () { prompt('Скопіюйте текст:', b.dataset.copy); });
    });

    document.addEventListener('admin:ready', function () {
        loadOrders();
        loadClients();
        loadReviews();
        loadSms();
    });
    document.addEventListener('admin:tab', function (e) {
        if (e.detail === 'orders') loadOrders();
        if (e.detail === 'clients') loadClients();
        if (e.detail === 'reviews') loadReviews();
        if (e.detail === 'sms') loadSms();
    });
})();
