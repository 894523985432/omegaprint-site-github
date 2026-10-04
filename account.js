jQuery(function ($) {
    var S = window.OmegaShop;
    var API = 'api/account.php';
    var $body = $('#accBody');
    if (!$body.length) return;

    function esc(s) { return S.esc(s == null ? '' : String(s)); }
    function post(action, data) {
        return $.ajax({ url: API + '?action=' + action, method: 'POST', data: data, dataType: 'json' })
            .then(null, function (xhr) { return $.Deferred().resolve(xhr.responseJSON || { ok: false, message: "Не вдалося зв'язатися із сервером." }); });
    }

    function date(s) {
        var d = new Date(String(s).replace(' ', 'T') + 'Z');
        return isNaN(d) ? '' : ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
    }
    function showErrors($form, res) {
        $form.find('.has-error').removeClass('has-error');
        $form.find('[data-err]').text('');
        $.each((res && res.errors) || {}, function (name, text) {
            $form.find('[data-err="' + name + '"]').text(text).closest('.form-group').addClass('has-error');
        });
        if (res && !res.ok && !res.errors) toast(res.message || 'Не вдалося зберегти.', true);
    }
    function toast(text, isError) {
        var $t = $('<div class="cms_toast">').toggleClass('err', !!isError).text(text).appendTo('body');
        setTimeout(function () { $t.addClass('show'); }, 20);
        setTimeout(function () { $t.remove(); }, 3500);
    }

    function showSaved($where, text) {
        $where.find('.acc_saved').remove();
        var $s = $('<span class="acc_saved" role="status">').text(text).appendTo($where);
        setTimeout(function () { $s.addClass('out'); }, 3000);
        setTimeout(function () { $s.remove(); }, 3400);
    }

    var $profile = $('#accProfile');
    var phoneMask = { mask: '+380 (99) 999-99-99', placeholder: '_', showMaskOnHover: false, clearIncomplete: false };

    function renderProfile(p) {
        $.each(['username', 'middle_name', 'last_name', 'birth_date', 'gender', 'phone'], function (_, name) {
            $profile.find('[name=' + name + ']').val(p[name] || '');
        });
        $('#acc-email').val(p.email);
        $('#acc-birth').attr('max', new Date().toISOString().slice(0, 10));
    }
    if ($.fn.inputmask) $('#acc-phone').inputmask(phoneMask);

    $profile.on('submit', function (e) {
        e.preventDefault();
        var $btn = $profile.find('button[type=submit]').prop('disabled', true);
        post('profile_save', $profile.serialize()).done(function (res) {
            $btn.prop('disabled', false);
            showErrors($profile, res);
            if (!res.ok) return;
            renderProfile(res.profile);
            showSaved($profile.find('.acc_actions'), 'Дані збережено');
        });
    });

    var $password = $('#accPassword');
    $('#accPassToggle').on('click', function () {
        $password.prop('hidden', !$password.prop('hidden'));
        if (!$password.prop('hidden')) $('#acc-current').trigger('focus');
    });
    $password.on('submit', function (e) {
        e.preventDefault();
        var $btn = $password.find('button[type=submit]').prop('disabled', true);
        post('password', $password.serialize()).done(function (res) {
            $btn.prop('disabled', false);
            showErrors($password, res);
            if (!res.ok) return;
            $password[0].reset();
            $password.prop('hidden', true);
            showSaved($profile.find('.acc_actions'), 'Пароль змінено');
        });
    });

    function num(n) { return String(n).replace(/\B(?=(\d{3})+$)/g, ' '); }
    function uah(n) { return num(n) + ' грн'; }

    function renderBonus(b) {
        var lv = b.level;
        $('#accBalance').text(Math.floor(b.balance));
        $('#accPercent').text(lv.percent + ' %');
        $('#accLevelName').text(lv.category_name);
        $('#accLevelText').text(lv.next === null ? 'найвища категорія — найбільший відсоток бонусів'
            : 'ще ' + uah(Math.max(1, Math.ceil(lv.next))) + ' покупок — і буде ' + lv.next_percent + ' %');
        $('#accLevels').html($.map(b.tiers, function (t, i) {
            var upTo = b.tiers[i + 1] ? b.tiers[i + 1].over : null;
            var range = upTo === null ? 'понад ' + uah(t.over) : i === 0 ? 'до ' + uah(upTo) : num(t.over + 1) + ' – ' + uah(upTo);
            return '<li' + (t.key === lv.category ? ' class="active"' : '') + '><b>' + t.percent + ' %</b><strong>' + esc(t.name) + '</strong>' + range + '</li>';
        }).join(''));
        $('#accHistory').prop('hidden', !b.log.length);
        $('#accBonusLog').html($.map(b.log, function (r) {
            return '<tr><td>' + date(r.created_at) + '</td><td>' + esc(r.reason) + '</td>' +
                '<td class="' + (r.delta > 0 ? 'plus' : 'minus') + '">' + (r.delta > 0 ? '+' : '−') + Math.abs(r.delta) + '</td></tr>';
        }).join(''));
    }

    var STEPS = [['new', 'Нове'], ['confirmed', 'Підтверджене'], ['shipped', 'Відправлене'], ['done', 'Виконано']];

    function orderHtml(o) {
        var at = -1;
        $.each(STEPS, function (i, s) { if (s[0] === o.status) at = i; });
        return '<article class="acc_order">' +
            '<div class="acc_order_head"><strong>Замовлення №' + o.id + '</strong><time>' + date(o.created_at) + '</time>' +
                '<span class="acc_status ' + esc(o.status) + '">' + esc(o.status_name) + '</span>' +
                '<span class="acc_sum">' + S.money(o.total) + '</span></div>' +
            (o.status === 'cancelled' ? '' : '<ol class="acc_steps">' + $.map(STEPS, function (s, i) {
                return '<li' + (i <= at ? ' class="on"' : '') + '>' + s[1] + '</li>';
            }).join('') + '</ol>') +
            '<ul class="acc_order_items">' + $.map(o.items, function (i) {
                var name = i.product_id ? '<a href="' + S.productUrl({ id: i.product_id }) + '">' + esc(i.name) + '</a>' : esc(i.name);
                return '<li>' + name + '<span>' + i.qty + ' × ' + S.money(i.price) + '</span></li>';
            }).join('') + '</ul>' +
            '<p class="acc_order_meta">' + esc(o.delivery_text) + ' · ' + esc(o.payment_text) +
                (o.bonus_spent ? '<br>Оплачено бонусами: ' + o.bonus_spent + ' грн, до сплати ' + S.money(o.to_pay) : '') +
                (o.bonus_earned ? '<br><b>Нараховано бонусів: ' + o.bonus_earned + '</b>' : '') + '</p>' +
        '</article>';
    }

    function loadOrders() {
        $.getJSON(API + '?action=orders').done(function (res) {
            if (!res.ok) return;
            $('#accOrders').html(res.orders.length ? $.map(res.orders, orderHtml).join('')
                : '<p class="acc_empty">Замовлень ще немає. <a href="shop.html">Перейти до каталогу</a></p>');
            $('#repairs').prop('hidden', !res.repairs.length);
            $('#accRepairs').html($.map(res.repairs, function (r) {
                return '<article class="acc_order"><div class="acc_order_head"><strong>Заявка №' + r.id + '</strong><time>' + date(r.created_at) + '</time>' +
                    '<span class="acc_status' + (r.closed ? ' done' : '') + '">' + esc(r.status_name) + '</span></div>' +
                    (r.device ? '<p class="acc_order_meta">' + esc(r.device) + '</p>' : '') + '</article>';
            }).join(''));
        });
    }

    var $address = $('#accAddress');
    var saved = null, city = null, warehouseCache = {};

    function combo($input, source, onPick) {
        var $list = $input.siblings('.np_list');
        var items = [], timer = null, request = null;
        function close() { $list.prop('hidden', true); $input.attr('aria-expanded', 'false'); }
        function search() {
            if (request && request.abort) request.abort();
            request = source($.trim($input.val()));
            if (!request) { close(); return; }
            $input.addClass('is-loading');
            request.done(function (list) {
                items = list;
                $list.html(items.length ? $.map(items, function (it, i) {
                    return '<li role="option" data-i="' + i + '">' + esc(it.label) + (it.hint ? '<small>' + esc(it.hint) + '</small>' : '') + '</li>';
                }).join('') + (items.more ? '<li class="empty">' + esc(items.more) + '</li>' : '') : '<li class="empty">Нічого не знайдено</li>').prop('hidden', false);
                $input.attr('aria-expanded', 'true');
            }).fail(function (xhr, status) {
                if (status === 'abort') return;
                $list.html('<li class="empty">' + esc((xhr.responseJSON && xhr.responseJSON.message) || 'Не вдалося завантажити дані «Нової пошти».') + '</li>').prop('hidden', false);
            }).always(function () { $input.removeClass('is-loading'); });
        }
        $input.on('input', function () { onPick(null); clearTimeout(timer); timer = setTimeout(search, 250); });
        $input.on('focus', function () { if (!$input.val() || items.length) search(); });
        $input.on('keydown', function (e) { if (e.key === 'Escape') close(); });
        $input.on('blur', function () { setTimeout(close, 150); });
        $list.on('mousedown', function (e) { e.preventDefault(); });
        $list.on('click', 'li[role=option]', function () {
            var it = items[+$(this).data('i')];
            if (!it) return;
            $input.val(it.label);
            onPick(it);
            close();
        });
        return { reset: function () { items = []; $input.val(''); close(); } };
    }
    function np(params) { return $.ajax({ url: 'api/np.php', data: params, dataType: 'json' }); }
    function field(name) { return $address.find('[name=' + name + ']'); }

    function setCity(c) {
        city = c;
        field('np_city').val(c ? c.name : '');
        field('np_city_ref').val(c ? c.city_ref : '');
        field('np_settlement_ref').val(c ? c.settlement_ref : '');
        $('#accWarehouse').prop('disabled', !c).attr('placeholder', c ? 'Номер або адреса — оберіть зі списку' : 'Спочатку оберіть місто');
    }
    function setWarehouse(w) {
        field('np_warehouse').val(w ? w.name : '');
        field('np_warehouse_ref').val(w ? w.ref : '');
    }
    function warehouses(q) {
        var d = field('delivery').val();
        if (!city || d === 'np_courier') return null;
        var type = d === 'np_postomat' ? 'postomat' : 'branch';
        var key = city.city_ref + ':' + type + ':' + q.toLowerCase();
        if (!warehouseCache[key]) {
            warehouseCache[key] = np({ action: 'warehouses', city_ref: city.city_ref, type: type, q: q }).then(function (res) {
                return { list: res.warehouses || [], total: res.total || 0 };
            });
            warehouseCache[key].fail(function () { delete warehouseCache[key]; });
        }
        return warehouseCache[key];
    }

    var warehouseCombo = combo($('#accWarehouse'), function (q) {
        var request = warehouses(q);
        if (!request) return null;
        return request.then(function (r) {
            var items = $.map(r.list, function (w) { return { label: w.name, value: w }; });
            if (r.total > items.length) items.more = 'Показано ' + items.length + ' з ' + r.total + ' — введіть номер або адресу, щоб знайти потрібне';
            return items;
        });
    }, function (it) { setWarehouse(it ? it.value : null); });

    var streetCombo = combo($('#accStreet'), function (q) {
        var ref = field('np_settlement_ref').val();
        if (!ref || q.length < 2) return null;
        return np({ action: 'streets', settlement_ref: ref, q: q }).then(function (res) {
            return $.map(res.streets || [], function (s) { return { label: s, value: s }; });
        });
    }, function () {});

    combo($('#accCity'), function (q) {
        if (q.length < 2) return null;
        return np({ action: 'cities', q: q }).then(function (res) {
            return $.map(res.cities || [], function (c) {
                return { label: c.name, value: c, hint: c.warehouses ? c.warehouses + ' відділень і поштоматів' : '' };
            });
        });
    }, function (it) {
        setCity(it ? it.value : null);
        warehouseCombo.reset();
        setWarehouse(null);
        streetCombo.reset();
    });

    function applyDeliveryType() {
        var d = field('delivery').val();
        $('#accWarehouseBox').prop('hidden', d === 'np_courier');
        $('#accCourierBox').prop('hidden', d !== 'np_courier');
        $('#accWarehouseLabel').text(d === 'np_postomat' ? 'Поштомат *' : 'Відділення *');
    }
    field('delivery').on('change', function () {
        warehouseCombo.reset();
        setWarehouse(null);
        applyDeliveryType();
    });

    function renderAddress(delivery, text) {
        saved = delivery;
        $address.prop('hidden', true);
        $('#accAddressView').prop('hidden', false).html(delivery
            ? '<p class="acc_address"><strong>' + esc(text) + '</strong>Підставляється сама під час оформлення замовлення.</p>' +
              '<p class="acc_actions"><button type="button" class="service_btn" id="accAddressEdit">Змінити</button>' +
              '<button type="button" class="acc_link" id="accAddressDelete">Видалити</button></p>'
            : '<p class="acc_address">Збережіть адресу «Нової пошти» — і на оформленні замовлення її не доведеться вводити вручну.</p>' +
              '<p class="acc_actions"><button type="button" class="service_btn" id="accAddressEdit">+ Додати адресу</button></p>');
    }

    $('#address').on('click', '#accAddressEdit', function () {
        var d = saved || { delivery: 'np_branch' };
        showErrors($address, null);
        field('delivery').val(d.delivery);
        setCity(d.np_city_ref ? { name: d.np_city, city_ref: d.np_city_ref, settlement_ref: d.np_settlement_ref || '' } : null);
        $('#accCity').val(d.np_city || '');
        $('#accWarehouse').val(d.np_warehouse || '');
        setWarehouse(d.np_warehouse_ref ? { name: d.np_warehouse, ref: d.np_warehouse_ref } : null);
        $('#accStreet').val(d.np_street || '');
        $('#accHouse').val(d.np_house || '');
        $('#accFlat').val(d.np_flat || '');
        applyDeliveryType();
        $('#accAddressView').prop('hidden', true);
        $address.prop('hidden', false);
    });
    $('#accAddressCancel').on('click', function () { $address.prop('hidden', true); $('#accAddressView').prop('hidden', false); });
    $('#address').on('click', '#accAddressDelete', function () {
        if (!confirm('Видалити збережену адресу?')) return;
        post('address_delete').done(function (res) { if (res.ok) renderAddress(res.delivery, res.delivery_text); });
    });
    $address.on('submit', function (e) {
        e.preventDefault();
        var $btn = $address.find('button[type=submit]').prop('disabled', true);
        post('address_save', $address.serialize()).done(function (res) {
            $btn.prop('disabled', false);
            showErrors($address, res);
            if (!res.ok) return;
            renderAddress(res.delivery, res.delivery_text);
            showSaved($('#accAddressView .acc_actions'), 'Адресу збережено');
        });
    });

    var resetToken = new URLSearchParams(location.search).get('reset');
    if (resetToken) {
        $('#accReset').prop('hidden', false);
        $('#accReset').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            $f.find('[data-err]').text('');
            $.ajax({ url: 'auth.php?action=reset_confirm', method: 'POST', dataType: 'json',
                     data: { token: resetToken, password: $f.find('[name=password]').val(), repeat: $f.find('[name=repeat]').val() } })
                .done(function () { location.replace('account.html'); })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    $.each(res.errors || {}, function (k, v) { $f.find('[data-err="' + k + '"]').text(v); });
                    if (!res.errors) $f.find('[data-err="form"]').text(res.message || "Не вдалося зв'язатися із сервером.");
                });
        });
    }

    $(document).on('omega:auth', function (e, user) {
        if (resetToken) { $('#accLoading, #accGuest').prop('hidden', true); return; }
        $('#accLoading').prop('hidden', true);
        $('#accGuest').prop('hidden', !!user);
        if (!user) { $body.prop('hidden', true); return; }
        $.getJSON(API + '?action=profile').done(function (res) {
            if (!res.ok) return;
            renderProfile(res.profile);
            renderBonus(res.bonus);
            renderAddress(res.delivery, res.delivery_text);
            $body.prop('hidden', false);
            loadOrders();
            if (location.hash && $(location.hash).length) $(location.hash)[0].scrollIntoView();
        });
    });
});
