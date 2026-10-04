jQuery(function ($) {
    var S = window.OmegaShop;

    var $items = $('#shopping .shopping_items');
    if ($items.length) {
        var $aside = $('.asaid_menu_shopping');

        var renderCartPage = function () {
            S.loadCart().done(function (cart) {
                if (!cart.lines.length) {
                    $items.html('<div class="catalog_empty">Кошик порожній. <a href="shop.html">Перейти до каталогу</a></div>');
                    $aside.closest('.asaid_menu_shopping_col').hide();
                    return;
                }
                $aside.closest('.asaid_menu_shopping_col').show();
                $items.html('<table><tbody>' + $.map(cart.lines, function (l) {
                    var p = l.product;
                    return '<tr class="block_shopping" data-id="' + p.id + '">' +
                        '<td class="img_shopping"><a href="' + S.productUrl(p) + '"><img src="' + S.esc(S.imageUrl(p)) + '" alt=""></a></td>' +
                        '<td class="text_shopping"><p><a href="' + S.productUrl(p) + '">' + S.esc(p.name) + '</a></p>' +
                            '<span>' + S.money(p.price) + '</span></td>' +
                        '<td class="td_number"><div class="coll">' +
                            '<button type="button" class="minus_cart" aria-label="Менше">-</button>' +
                            '<input type="number" min="1" max="999" class="coll_number" value="' + l.qty + '" aria-label="Кількість">' +
                            '<button type="button" class="plus_cart" aria-label="Більше">+</button>' +
                        '</div></td>' +
                        '<td class="price_td"><div class="price">' + S.money(l.sum) + '</div></td>' +
                        '<td class="close_product"><button type="button" class="js-cart-remove" data-id="' + p.id + '" title="Видалити" aria-label="Видалити"><i class="fas fa-times"></i></button></td>' +
                    '</tr>';
                }).join('') + '</tbody></table>');
                $aside.find('.shopping_price h3').text(S.money(cart.total));
            }).fail(function () {
                $items.html('<div class="catalog_empty">Не вдалося завантажити кошик. Спробуйте оновити сторінку.</div>');
            });
        };

        $items.on('click', '.minus_cart, .plus_cart', function () {
            var $row = $(this).closest('tr');
            var qty = parseInt($row.find('.coll_number').val(), 10) || 1;
            qty += $(this).hasClass('plus_cart') ? 1 : -1;
            if (qty >= 1 && qty <= 999) S.Cart.set($row.data('id'), qty);
        });
        $items.on('change', '.coll_number', function () {
            var qty = Math.max(1, Math.min(999, parseInt($(this).val(), 10) || 1));
            S.Cart.set($(this).closest('tr').data('id'), qty);
        });

        $(document).on('omega:cart', renderCartPage);
        renderCartPage();
    }

    var $form = $('#form-checkout');
    if (!$form.length) return;

    var $summary = $('.review-checkout-block');
    var NP_API = 'api/np.php';

    if ($form.data('yiiActiveForm')) $form.yiiActiveForm('destroy');
    $form.off('submit');

    var PAYMENTS = { pickup: ['cash', 'invoice'], np: ['cod', 'invoice'] };

    function delivery() { return $form.find('input[name=delivery]:checked').val() || 'pickup'; }

    function applyDelivery() {
        var d = delivery();
        var isNp = d.indexOf('np_') === 0;
        $('#npBox').prop('hidden', !isNp);
        $('#npWarehouseBox').prop('hidden', !isNp || d === 'np_courier');
        $('#npCourierBox').prop('hidden', d !== 'np_courier');
        $('#npWarehouseLabel').text(d === 'np_postomat' ? 'Поштомат *' : 'Відділення *');
        if (isNp) loadWarehouses();

        var allowed = PAYMENTS[isNp ? 'np' : 'pickup'];
        $form.find('.co_option[data-payment]').each(function () {
            var ok = allowed.indexOf($(this).data('payment')) !== -1;
            $(this).toggleClass('is-disabled', !ok).find('input').prop('disabled', !ok);
        });
        var $checked = $form.find('input[name=payment]:checked');
        if (!$checked.length || $checked.prop('disabled')) {
            $form.find('input[name=payment][value=' + allowed[0] + ']').prop('checked', true);
        }
        clearError('delivery');
        clearError('payment');
    }
    $form.on('change', 'input[name=delivery]', applyDelivery);

    function combo($input, source, onPick) {
        var $list = $input.siblings('.np_list');
        var items = [], active = -1, timer = null, request = null;

        function render() {
            $list.html(items.length ? items.map(function (it, i) {
                return '<li role="option" data-i="' + i + '">' + S.esc(it.label) + (it.hint ? '<small>' + S.esc(it.hint) + '</small>' : '') + '</li>';
            }).join('') + (items.more ? '<li class="empty">' + S.esc(items.more) + '</li>' : '') : '<li class="empty">Нічого не знайдено</li>');
            $list.prop('hidden', false);
            $input.attr('aria-expanded', 'true');
            active = -1;
        }
        function close() { $list.prop('hidden', true); $input.attr('aria-expanded', 'false'); }
        function search() {
            if (request && request.abort) request.abort();
            var q = $.trim($input.val());
            request = source(q);
            if (!request) { close(); return; }
            $input.addClass('is-loading');
            request.done(function (list) { items = list; render(); })
                .fail(function (xhr, status) {
                    if (status === 'abort') return;
                    items = [];
                    $list.html('<li class="empty">' + S.esc((xhr.responseJSON && xhr.responseJSON.message) || 'Не вдалося завантажити дані «Нової пошти».') + '</li>').prop('hidden', false);
                })
                .always(function () { $input.removeClass('is-loading'); });
        }
        $input.on('input', function () {
            onPick(null);
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });
        $input.on('focus', function () { if (!$input.val() || items.length) search(); });
        $input.on('keydown', function (e) {
            var $li = $list.find('li[role=option]');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if ($list.prop('hidden')) return search();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + $li.length) % $li.length;
                $li.removeClass('active').eq(active).addClass('active')[0].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (!$list.prop('hidden') && active >= 0) { e.preventDefault(); pick(active); }
            } else if (e.key === 'Escape') {
                close();
            }
        });
        $list.on('mousedown', function (e) { e.preventDefault(); });
        $list.on('click', 'li[role=option]', function () { pick(+$(this).data('i')); });
        $input.on('blur', function () { setTimeout(close, 150); });
        function pick(i) {
            var it = items[i];
            if (!it) return;
            $input.val(it.label);
            onPick(it);
            close();
        }
        return { reset: function () { items = []; $input.val(''); close(); } };
    }

    function npGet(params) {
        return $.ajax({ url: NP_API, data: params, dataType: 'json' });
    }

    var city = null;
    var warehouseCache = {};

    var cityCombo = combo($('#npCity'), function (q) {
        if (q.length < 2) return null;
        return npGet({ action: 'cities', q: q }).then(function (res) {
            return (res.cities || []).map(function (c) {
                return { label: c.name, value: c, hint: c.warehouses ? c.warehouses + ' відділень і поштоматів' : '' };
            });
        });
    }, function (it) {
        city = it ? it.value : null;
        $form.find('[name=np_city]').val(city ? city.name : '');
        $form.find('[name=np_city_ref]').val(city ? city.city_ref : '');
        $('#npSettlementRef').val(city ? city.settlement_ref : '');
        if (it) clearError('np_city');
        warehouseCombo.reset();
        setWarehouse(null);
        streetCombo.reset();
        $('#npWarehouse').prop('disabled', !city).attr('placeholder', city ? 'Номер або адреса — оберіть зі списку' : 'Спочатку оберіть місто');
        if (city) loadWarehouses();
    });

    function loadWarehouses(q) {
        var d = delivery();
        if (!city || d === 'np_courier' || d.indexOf('np_') !== 0) return null;
        var type = d === 'np_postomat' ? 'postomat' : 'branch';
        q = q || '';
        var key = city.city_ref + ':' + type + ':' + q.toLowerCase();
        if (!warehouseCache[key]) {
            warehouseCache[key] = npGet({ action: 'warehouses', city_ref: city.city_ref, type: type, q: q }).then(function (res) {
                return { list: res.warehouses || [], total: res.total || 0 };
            });
            warehouseCache[key].fail(function () { delete warehouseCache[key]; });
        }
        return warehouseCache[key];
    }

    function setWarehouse(w) {
        $form.find('[name=np_warehouse]').val(w ? w.name : '');
        $form.find('[name=np_warehouse_ref]').val(w ? w.ref : '');
        if (w) clearError('np_warehouse');
    }

    var warehouseCombo = combo($('#npWarehouse'), function (q) {
        var request = loadWarehouses(q);
        if (!request) return null;
        return request.then(function (r) {
            var items = r.list.map(function (w) { return { label: w.name, value: w }; });
            if (r.total > items.length) items.more = 'Показано ' + items.length + ' з ' + r.total + ' — введіть номер або адресу, щоб знайти потрібне';
            return items;
        });
    }, function (it) { setWarehouse(it ? it.value : null); });

    var streetCombo = combo($('#npStreet'), function (q) {
        var ref = $('#npSettlementRef').val();
        if (!ref || q.length < 2) return null;
        return npGet({ action: 'streets', settlement_ref: ref, q: q }).then(function (res) {
            return (res.streets || []).map(function (s) { return { label: s, value: s }; });
        });
    }, function (it) { if (it) clearError('np_street'); });

    applyDelivery();

    function errorBox(name) {
        var $box = $form.find('[data-error="' + name + '"]');
        if (!$box.length) $box = $form.find('.field-order-' + name + ' .help-block-error');
        return $box;
    }
    function setError(name, text) {
        errorBox(name).text(text).closest('.form-group').addClass('has-error');
        if (!errorBox(name).closest('.form-group').length) errorBox(name).addClass('is-visible');
    }
    function clearError(name) {
        errorBox(name).text('').removeClass('is-visible').closest('.form-group').removeClass('has-error');
    }

    function validate() {
        $form.find('.has-error').removeClass('has-error');
        $form.find('.help-block-error').text('').removeClass('is-visible');
        var ok = true;
        function need(name, value, text) { if (!$.trim(value)) { setError(name, text); ok = false; } }
        need('f_name', $('#order-f_name').val(), 'Вкажіть прізвище');
        need('username', $('#order-username').val(), "Вкажіть ім'я");
        var phone = $('#order-phone')[0];
        if (!$.trim($(phone).val())) { setError('phone', 'Вкажіть телефон'); ok = false; }
        else if (phone.inputmask && !phone.inputmask.isComplete()) { setError('phone', 'Введіть номер повністю: +380 (XX) XXX-XX-XX'); ok = false; }
        var email = $.trim($('#order-email').val());
        if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { setError('email', 'Некоректний email'); ok = false; }

        var d = delivery();
        if (d.indexOf('np_') === 0) {
            if (!$form.find('[name=np_city_ref]').val()) { setError('np_city', 'Оберіть місто зі списку'); ok = false; }
            else if (d === 'np_courier') {
                if (!$.trim($('#npStreet').val()) || !$.trim($('#npHouse').val())) { setError('np_street', 'Вкажіть вулицю і номер будинку'); ok = false; }
            } else if (!$form.find('[name=np_warehouse_ref]').val()) {
                setError('np_warehouse', d === 'np_postomat' ? 'Оберіть поштомат зі списку' : 'Оберіть відділення зі списку');
                ok = false;
            }
        }
        if (!$form.find('input[name=payment]:checked').length) { setError('payment', 'Оберіть спосіб оплати'); ok = false; }
        if (!$('#captchaBox').prop('hidden') && !/^\d{5}$/.test($.trim($('#captchaInput').val()))) { setError('captcha', 'Введіть 5 цифр з картинки'); ok = false; }

        if (!ok) {
            var $first = $form.find('.has-error, .help-block-error.is-visible').first();
            $('html, body').animate({ scrollTop: $first.offset().top - 140 }, 250);
            $first.find('input:visible').first().trigger('focus');
        }
        return ok;
    }

    var cartReady = false;
    $form.on('submit', function (e) {
        if (!cartReady || !validate()) { e.preventDefault(); return; }
        $form.find('input[name=cart]').val(JSON.stringify(S.Cart.read()));
        $summary.find('.bay input').prop('disabled', true).val('Надсилаємо…');
    });
    $summary.on('click', '.bay input', function () { $form.trigger('submit'); });

    $(window).on('pageshow', function () { $summary.find('.bay input').val('Оформити замовлення').prop('disabled', !cartReady); });

    function renderSummary() {
        S.loadCart().done(function (cart) {
            cartReady = cart.lines.length > 0;
            var $aside = $summary.find('.cart_menu');
            if (!cartReady) {
                $aside.find('.filter_title strong').text('Кошик порожній');
                $aside.find('.cart_block').html('<tbody><tr><td class="p-3"><a href="shop.html">Перейти до каталогу</a></td></tr></tbody>');
                $aside.find('.bay_price strong').text(S.money(0));
                $aside.find('.bay input').prop('disabled', true);
                return;
            }
            $aside.find('.filter_title strong').text('У кошику ' + cart.count + ' ' + S.plural(cart.count, 'товар', 'товари', 'товарів'));
            $aside.find('.cart_block').html('<tbody>' + $.map(cart.lines, function (l) {
                return '<tr class="cart_item">' +
                    '<td class="cart_img"><img src="' + S.esc(S.imageUrl(l.product)) + '" alt=""></td>' +
                    '<td class="cart_text"><p>' + S.esc(l.product.name) + '</p>' +
                        '<strong>' + S.money(l.product.price) + '</strong><span> × ' + l.qty + ' шт.</span></td>' +
                '</tr>';
            }).join('') + '</tbody>');
            $aside.find('.bay_price strong').text(S.money(cart.total));
            $aside.find('.bay_delivery strong').text(delivery() === 'pickup' ? 'Безкоштовно' : 'За тарифами «Нової пошти»');
            $aside.find('.bay input').prop('disabled', false);
        });
    }
    $form.on('change', 'input[name=delivery]', function () {
        $summary.find('.bay_delivery strong').text(delivery() === 'pickup' ? 'Безкоштовно' : 'За тарифами «Нової пошти»');
    });
    $(document).on('omega:cart', renderSummary);
    renderSummary();

    function newCaptcha() { $('#captchaImg').attr('src', 'api/captcha.php?action=image&t=' + Date.now()); $('#captchaInput').val(''); }
    $('#captchaNew').on('click', newCaptcha);
    function checkCaptcha() {
        $.getJSON('api/captcha.php?action=need').done(function (res) {
            var need = !!(res && res.need);
            $('#captchaBox').prop('hidden', !need);
            $('#captchaInput').prop('disabled', !need);
            if (need) newCaptcha();
        });
    }
    checkCaptcha();

    $(window).on('pageshow', function (e) { if (e.originalEvent && e.originalEvent.persisted) checkCaptcha(); });

    var account = null, cartTotal = 0;
    var $bonusUse = $('#bonusUse');

    function renderBonus() {
        var balance = account ? Math.floor(account.bonus.balance) : 0;

        var max = Math.max(0, Math.min(balance, Math.floor(cartTotal * (account ? account.bonus.spend_share : 0))));
        var use = Math.max(0, Math.min(max, parseInt($bonusUse.val(), 10) || 0));
        if (String(use) !== $bonusUse.val() && document.activeElement !== $bonusUse[0]) $bonusUse.val(use);
        $bonusUse.attr('max', max);
        $('#bonusBalance').text(balance);
        $('#bonusUseRow').prop('hidden', max < 1);
        $('#bonusMax').text('до ' + max + ' грн — не більше половини суми замовлення');
        $('#bayBonus').prop('hidden', !use).find('strong').text('− ' + S.money(use));
        $('#bayToPay').prop('hidden', !use).find('strong').text(S.money(cartTotal - use));
        if (account) {
            var earn = Math.min(account.bonus.max_earn, Math.floor((cartTotal - use) * account.bonus.level.percent / 100));
            $('#bonusEarn').text('Після виконання замовлення нарахуємо ' + earn + ' ' + S.plural(earn, 'бонус', 'бонуси', 'бонусів') +
                ' (' + account.bonus.level.percent + ' % від суми).');
        }
    }
    $bonusUse.on('input change', renderBonus);
    $bonusUse.on('blur', function () { $bonusUse.val(parseInt($bonusUse.val(), 10) || 0); renderBonus(); });
    $('#bonusAll').on('click', function () { $bonusUse.val(1e9); renderBonus(); });
    $(document).on('omega:cart', function () { S.loadCart().done(function (cart) { cartTotal = cart.total; renderBonus(); }); });
    S.loadCart().done(function (cart) { cartTotal = cart.total; renderBonus(); });

    function fillIfEmpty(sel, value) {
        var $el = $(sel);
        var empty = !$.trim($el.val()) || ($el[0].inputmask && !$el[0].inputmask.unmaskedvalue());
        if (value && empty) $el.val(value).trigger('change');
    }

    function applySavedAddress(d) {
        if (!d || !d.np_city_ref || $form.find('[name=np_city_ref]').val() || delivery() !== 'pickup') return;
        $form.find('input[name=delivery][value=' + d.delivery + ']').prop('checked', true);
        city = { name: d.np_city, city_ref: d.np_city_ref, settlement_ref: d.np_settlement_ref || '' };
        $('#npCity').val(d.np_city);
        $form.find('[name=np_city]').val(d.np_city);
        $form.find('[name=np_city_ref]').val(d.np_city_ref);
        $('#npSettlementRef').val(city.settlement_ref);
        $('#npWarehouse').prop('disabled', false).attr('placeholder', 'Номер або адреса — оберіть зі списку');
        if (d.delivery !== 'np_courier' && d.np_warehouse_ref) {
            $('#npWarehouse').val(d.np_warehouse);
            setWarehouse({ name: d.np_warehouse, ref: d.np_warehouse_ref });
        }
        $('#npStreet').val(d.np_street || '');
        $('#npHouse').val(d.np_house || '');
        $('#npFlat').val(d.np_flat || '');
        applyDelivery();
        $summary.find('.bay_delivery strong').text('За тарифами «Нової пошти»');
    }

    $form.on('change', 'input[name=delivery]', function () {
        var d = account && account.delivery;
        if (!d || !$form.find('[name=np_warehouse_ref]').val()) return;
        if (delivery() !== d.delivery && $form.find('[name=np_warehouse_ref]').val() === d.np_warehouse_ref) {
            warehouseCombo.reset();
            setWarehouse(null);
        }
    });

    $(document).on('omega:auth', function (e, user) {
        account = null;
        $('#bonusGuest').prop('hidden', !!user);
        $('#bonusBox, #saveAddressBox').prop('hidden', true).find('input').prop('disabled', true);
        renderBonus();
        if (!user) return;
        $.getJSON('api/account.php?action=profile').done(function (res) {
            if (!res.ok) return;
            account = res;
            $('#bonusBox, #saveAddressBox').prop('hidden', false).find('input').prop('disabled', false);
            fillIfEmpty('#order-username', res.profile.username);
            fillIfEmpty('#order-f_name', res.profile.last_name);
            fillIfEmpty('#order-l_name', res.profile.middle_name);
            fillIfEmpty('#order-phone', res.profile.phone);
            fillIfEmpty('#order-email', res.profile.email);
            applySavedAddress(res.delivery);
            renderBonus();
        });
    });
});
