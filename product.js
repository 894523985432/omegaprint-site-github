jQuery(function ($) {
    var S = window.OmegaShop;
    var $view = $('#productView');
    var id = parseInt(new URLSearchParams(location.search).get('id'), 10);

    function notFound() {
        $view.html('<div class="catalog_empty">Товар не знайдено. <a href="shop.html">Перейти до каталогу</a></div>');
        document.title = 'Товар не знайдено | OmegaPrint - Сервісний центр';
    }

    function infoHtml(info) {
        if (!info || (!info.payment && !info.warranty)) return '';
        var card = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></svg>';
        var shield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7.5 3v5.5c0 4.6-3.1 8.2-7.5 9.5-4.4-1.3-7.5-4.9-7.5-9.5V6z"/><path d="M8.8 12.2l2.3 2.3 4.2-4.6"/></svg>';
        function part(icon, title, text) {
            return text ? '<div class="product_info_item">' + icon + '<div><strong>' + title + '</strong><p>' + S.esc(text) + '</p></div></div>' : '';
        }

        var warranty = info.warranty;
        if (info.warranty_months) {
            warranty = info.warranty_months + ' ' + S.plural(info.warranty_months, 'місяць', 'місяці', 'місяців') + '. ' +
                String(warranty || '').replace(/^Гарантія виробника\.\s*/, '');
        }
        return '<section class="product_info">' + part(card, 'Оплата', info.payment) + part(shield, 'Гарантія', warranty) + '</section>';
    }

    function specsHtml(specs, isAdmin) {
        if (!specs.length) return '';
        var missing = 0;
        var rows = $.map(specs, function (s) {
            var value;
            if (s.value === null) { missing++; value = '(Потрібна інформація)'; }
            else if (s.type === 'bool') value = s.value === '1' ? 'Так' : 'Ні';
            else value = S.esc(s.value) + (s.unit ? ' ' + S.esc(s.unit) : '');
            return '<div class="spec_row' + (s.value === null ? ' is-missing' : '') + '">' +
                '<dt><span>' + S.esc(s.name) + '</span>' +
                    (s.help ? '<button type="button" class="spec_help" aria-expanded="false" aria-label="Що це означає?">?</button>' : '') + '</dt>' +
                '<dd>' + value + '</dd>' +
                (s.help ? '<p class="spec_help_text" hidden>' + S.esc(s.help) + '</p>' : '') +
            '</div>';
        }).join('');
        return '<section class="product_specs"><h2>Характеристики</h2><dl>' + rows + '</dl>' +
            (isAdmin && missing ? '<p class="spec_admin_note">Рядки «(Потрібна інформація)» бачать лише адміністратори. ' +
                'Заповнити: адмін-панель → Товари → Змінити.</p>' : '') +
        '</section>';
    }

    if (!id) return notFound();

    S.api({ action: 'product', id: id }).done(function (res) {
        var p = res.product;
        var d = S.discount(p);
        document.title = p.name + ' — купити в Києві, ціна | OmegaPrint - Сервісний центр';

        var crumbs = '<li><a href="index.html">Головна</a></li><li><a href="shop.html">Інтернет-магазин</a></li>';
        $.each(res.path, function (_, c) {
            crumbs += '<li><a href="shop.html?cat=' + c.id + '">' + S.esc(c.name) + '</a></li>';
        });
        $('#productBreadcrumb').html(crumbs);

        var description = p.description
            ? $.map(p.description.split(/\n{2,}/), function (para) {
                return '<p>' + S.esc(para).replace(/\n/g, '<br>') + '</p>';
            }).join('')
            : '<p class="text-gray">Детальний опис уточнюйте в менеджера за телефоном або через форму «Передзвоніть мені».</p>';

        $view.html(
            '<h1 class="title">' + S.esc(p.name) + '</h1>' +
            '<div class="row">' +
                '<div class="col-12 col-md-6">' +
                    '<div class="product_photo">' +
                        (d ? '<div class="discount_l">-' + d + '%</div>' : '') +
                        '<img src="' + S.esc(S.imageUrl(p)) + '" alt="' + S.esc(p.name) + '">' +
                        (p.image ? '' : '<p class="product_photo_note">Зображення умовне</p>') +
                    '</div>' +
                '</div>' +
                '<div class="col-12 col-md-6">' +
                    '<div class="product_buy">' +
                        (p.sku ? '<p class="text-gray mb-2">Артикул: ' + S.esc(p.sku) + '</p>' : '') +
                        '<p class="mb-3">' + S.stockLabel(p) + '</p>' +
                        (d ? '<s class="old_price">' + S.money(p.old_price) + '</s>' : '') +
                        '<div class="product_price">' + S.money(p.price) + '</div>' +
                        (S.canBuy(p) ? '<div class="product_actions">' :
                            '<p class="product_absent">Цього товару зараз немає. Залиште заявку — повідомимо, коли з’явиться.</p><div class="product_actions" hidden>') +
                            '<div class="coll product_qty">' +
                                '<button type="button" class="minus" aria-label="Менше">−</button>' +
                                '<input type="number" min="1" max="999" value="1" class="coll_number" id="productQty" aria-label="Кількість">' +
                                '<button type="button" class="plus" aria-label="Більше">+</button>' +
                            '</div>' +
                            '<button type="button" class="blue-button product_add" id="productAdd">Додати в кошик</button>' +
                        '</div>' +
                        '<button type="button" class="call_me_product" data-toggle="modal" data-target="#callMeProductModal" data-name="' + S.esc(p.name) + '" data-image="' + S.esc(S.imageUrl(p)) + '">Передзвоніть мені</button>' +
                        '<button type="button" class="call_me_product ask_product" data-toggle="modal" data-target="#productWriteModal" data-name="' + S.esc(p.name) + '" data-image="' + S.esc(S.imageUrl(p)) + '">Задати питання</button>' +
                        '<p class="product_note" id="productNote" aria-live="polite"></p>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            infoHtml(res.info) +
            specsHtml(res.specs || [], res.admin) +
            '<section class="product_description"><h2>Опис</h2>' + description + '</section>'
        );
        updateNote();
    }).fail(notFound);

    function qty() {
        return Math.max(1, Math.min(999, parseInt($('#productQty').val(), 10) || 1));
    }

    function updateNote() {
        var inCart = S.Cart.read()[id];
        $('#productNote').html(inCart
            ? 'У кошику: ' + inCart + ' шт. <a href="tray.html">Перейти до кошика</a>'
            : '');
    }

    $view.on('click', '.spec_help', function () {
        var $text = $(this).closest('.spec_row').find('.spec_help_text');
        var open = $text.prop('hidden');
        $text.prop('hidden', !open);
        $(this).attr('aria-expanded', open);
    });
    $view.on('click', '.minus', function () { $('#productQty').val(Math.max(1, qty() - 1)); });
    $view.on('click', '.plus', function () { $('#productQty').val(Math.min(999, qty() + 1)); });
    $view.on('change', '#productQty', function () { $(this).val(qty()); });
    $view.on('click', '#productAdd', function () {
        S.Cart.add(id, qty());
    });
    $(document).on('omega:cart', updateNote);
});
