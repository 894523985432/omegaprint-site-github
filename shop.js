(function ($) {
    var API = 'api/shop.php';
    var STORAGE_KEY = 'omega_cart';
    var NO_IMAGE = 'icons/no-image.svg';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function money(n) {
        return Number(n).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            .replace(/ /g, ' ') + ' грн.';
    }

    function plural(n, one, few, many) {
        var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return one;
        if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
        return many;
    }

    var Cart = {
        read: function () {
            try {
                var data = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                return data && typeof data === 'object' ? data : {};
            } catch (e) {
                return {};
            }
        },
        write: function (items) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(items)); } catch (e) {   }
            $(document).trigger('omega:cart');
        },
        set: function (id, qty) {
            var items = Cart.read();
            qty = Math.max(0, Math.min(999, parseInt(qty, 10) || 0));
            if (qty) items[id] = qty; else delete items[id];
            Cart.write(items);
        },
        add: function (id, qty) {
            Cart.set(id, (Cart.read()[id] || 0) + (qty || 1));
        },
        remove: function (id) { Cart.set(id, 0); },
        clear: function () { Cart.write({}); },
        ids: function () { return Object.keys(Cart.read()); },
        count: function () {
            var items = Cart.read(), sum = 0;
            for (var id in items) sum += items[id];
            return sum;
        }
    };

    function api(params) {
        return $.ajax({ url: API, data: params, dataType: 'json', cache: false });
    }

    function loadCart() {
        var items = Cart.read();
        var ids = Object.keys(items);
        if (!ids.length) return $.Deferred().resolve({ lines: [], total: 0, count: 0 }).promise();
        return api({ action: 'products', ids: ids.join(','), per: 100 }).then(function (res) {
            var byId = {};
            $.each(res.items || [], function (_, p) { byId[p.id] = p; });
            var lines = [], total = 0, count = 0, changed = false;
            $.each(ids, function (_, id) {
                var p = byId[id];
                if (!p) { delete items[id]; changed = true; return; }
                var sum = p.price * items[id];
                lines.push({ product: p, qty: items[id], sum: sum });
                total += sum;
                count += items[id];
            });
            if (changed) Cart.write(items);
            return { lines: lines, total: total, count: count };
        });
    }

    function productUrl(p) { return 'product.html?id=' + p.id; }
    function imageUrl(p) { return p.image || p.placeholder || NO_IMAGE; }

    var AVAILABILITY = {
        in_stock: { cls: 'in_stock', icon: 'fa-check-circle', text: 'В наявності' },
        on_order: { cls: 'on_order', icon: 'fa-clock', text: 'Під замовлення' },
        absent:   { cls: 'absent', icon: 'fa-times-circle', text: 'Відсутній' }
    };

    function availability(p) {
        return AVAILABILITY[p.availability] || (p.in_stock ? AVAILABILITY.in_stock : AVAILABILITY.absent);
    }

    function stockLabel(p) {
        var a = availability(p);
        return '<span class="stock stock_' + a.cls + '" title="' + a.text + '"><i class="fas ' + a.icon + '"></i> ' + a.text + '</span>';
    }

    function discount(p) {
        return p.old_price && p.old_price > p.price ? Math.round((1 - p.price / p.old_price) * 100) : 0;
    }

    function canBuy(p) {
        return p.availability !== 'absent';
    }

    function buyButton(p) {
        if (!canBuy(p)) return '<span class="bt_bay bt_absent" aria-disabled="true">Немає</span>';
        var inCart = !!Cart.read()[p.id];
        return inCart
            ? '<a href="tray.html" class="bt_bay bt_in_cart" data-id="' + p.id + '">В кошику</a>'
            : '<input type="button" value="Купити" class="bt_bay js-buy" data-id="' + p.id + '">';
    }

    function productCard(p) {
        var d = discount(p);
        return '<div class="poruct_block' + (d ? ' has_discount' : '') + '" data-product-id="' + p.id + '">' +
            '<a href="' + productUrl(p) + '">' +
                '<div class="img_gallary">' +
                    (d ? '<div class="discount_l">-' + d + '%</div>' : '') +
                    '<img src="' + esc(imageUrl(p)) + '" alt="' + esc(p.name) + '" loading="lazy">' +
                '</div>' +
                '<div class="info_gallary">' +
                    (p.sku ? '<span>Артикул: ' + esc(p.sku) + '</span>' : '') +
                    '<p>' + esc(p.name) + ' <br>' + stockLabel(p) + '</p>' +
                '</div>' +
            '</a>' +
            '<div class="button_broduct">' +
                (d ? '<s class="old_price">' + money(p.old_price) + '</s>' : '') +
                '<h5 role="none">' + money(p.price) + '</h5>' +
                buyButton(p) +
                '<button type="button" class="call_me_product" data-toggle="modal" data-target="#callMeProductModal" data-name="' + esc(p.name) + '" data-image="' + esc(imageUrl(p)) + '">Передзвоніть мені</button>' +
            '</div>' +
        '</div>';
    }

    function renderHeaderCart() {
        var $label = $('.cartMenuLinkSpan');
        var $content = $('#gCartContent');
        var count = Cart.count();
        $label.text(count ? 'Кошик (' + count + ')' : 'Кошик');

        $('.number_cart').toggle(count > 0).find('span').text(count);
        if (!$content.length) return;
        if (!count) {
            $content.html('<p class="cart_empty">Кошик порожній</p>');
            return;
        }
        loadCart().done(function (cart) {
            if (!cart.lines.length) {
                $content.html('<p class="cart_empty">Кошик порожній</p>');
                return;
            }
            var rows = $.map(cart.lines, function (l) {
                return '<tr class="block_shopping">' +
                    '<td class="img_shopping"><a href="' + productUrl(l.product) + '"><img src="' + esc(imageUrl(l.product)) + '" alt=""></a></td>' +
                    '<td class="text_shopping"><p><a href="' + productUrl(l.product) + '">' + esc(l.product.name) + '</a></p>' +
                        '<span>' + l.qty + ' × ' + money(l.product.price) + '</span></td>' +
                    '<td class="close_product_dynamic js-cart-remove" data-id="' + l.product.id + '" title="Видалити"><i class="fas fa-times"></i></td>' +
                '</tr>';
            }).join('');
            $content.html(
                '<div class="cart_list cart_list_f"><table class="w-100"><tbody>' + rows + '</tbody></table></div>' +
                '<div class="cart_null_bottom cart_product w-100">' +
                    '<div class="price-cart"><p class="d_inline">Всього:</p> <span><strong class="cart_popup_total">' + money(cart.total) + '</strong></span></div>' +
                    '<a class="float-right cart_link" href="tray.html">Перейти до кошика</a>' +
                '</div>'
            );
        });
    }

    function refreshBuyButtons() {
        var items = Cart.read();
        $('.js-buy, .bt_in_cart').each(function () {
            var $b = $(this), id = $b.data('id');
            if (!!items[id] === $b.hasClass('bt_in_cart')) return;
            $b.replaceWith(items[id]
                ? '<a href="tray.html" class="bt_bay bt_in_cart" data-id="' + id + '">В кошику</a>'
                : '<input type="button" value="Купити" class="bt_bay js-buy" data-id="' + id + '">');
        });
    }

    function renderPopular() {
        var $slider = $('.slick.popular_main');
        if (!$slider.length) return;
        api({ action: 'products', popular: 1, per: 20 }).then(function (res) {
            if (res.items.length) return res;

            return api({ action: 'products', stock: 1, sort: 'new', per: 60 }).then(function (fresh) {
                fresh.items = $.grep(fresh.items, function (p) { return !!p.image; }).slice(0, 12);
                return fresh;
            });
        }).done(function (res) {
            var settings = $slider.hasClass('slick-initialized') ? $slider.slick('getSlick').originalSettings : null;
            if (settings) $slider.slick('unslick');
            if (!res.items.length) {
                $slider.closest('section, .container').find('h2').first().closest('section').hide();
            }
            $slider.html($.map(res.items, productCard).join(''));
            if (settings) $slider.slick(settings);
        });
    }

    $(function () {

        $('.button_broduct .bt_bay').off('click');
        $('.cart_control .cart_list_f').off('click');
        $('.serch_block input.serch, .serch_block_sm input.serch').off('keyup');
        $('.serch_popup').remove();

        $('#searchForm, #searchFormSm').attr({ action: 'shop.html', method: 'get' })
            .find('input[name=search]').attr('name', 'q');
        var q = new URLSearchParams(location.search).get('q');
        if (q) $('#searchForm input[name=q], #searchFormSm input[name=q]').val(q);
        $('#searchForm, #searchFormSm').each(function () { searchSuggest($(this)); });

        $('a').filter(function () { return $.trim($(this).text()) === 'Інтернет-магазин'; }).attr('href', 'shop.html');
        $('a').filter(function () { return /^Всі товари/.test($.trim($(this).text())); }).attr('href', 'shop.html');
        linkMenuCategories();

        $(document).on('click', '.js-buy', function () {
            var id = $(this).data('id');
            Cart.add(id, 1);
        });
        $(document).on('click', '.js-cart-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Cart.remove($(this).data('id'));
        });

        $(document).on('click', '.call_me_product[data-name]', function () {
            var $modal = $('#callMeProductModal');
            $modal.find('.call_me_product_name').text($(this).data('name'));
            if ($(this).data('image')) $modal.find('.product_image_src').attr({ src: $(this).data('image'), alt: $(this).data('name') });
            $modal.find('input[name="CallMe[descript]"], textarea[name="CallMe[descript]"]')
                .val('Товар: ' + $(this).data('name'));
        });

        $(document).on('click', '.ask_product[data-name]', function () {
            var $modal = $('#productWriteModal'), name = $(this).data('name');
            $modal.find('.write_product_name').text(name);
            if ($(this).data('image')) $modal.find('.product_image_src').attr({ src: $(this).data('image'), alt: name });
            $modal.find('input[name="Writing[title]"]').val('Питання про товар: ' + name);
        });

        $(document).on('omega:cart', function () {
            renderHeaderCart();
            refreshBuyButtons();
        });

        $(window).on('storage', function (e) {
            if (e.originalEvent.key === STORAGE_KEY) $(document).trigger('omega:cart');
        });

        renderHeaderCart();
        renderPopular();
        renderSale();
    });

    function renderSale() {
        var $grid = $('#saleGrid');
        if (!$grid.length) return;
        api({ action: 'products', sale: 1, per: 48 }).done(function (res) {
            $grid.html(res.items.length
                ? $.map(res.items, function (p) {
                    return '<div class="col-12 col-sm-6 col-md-4 col-lg-3">' + productCard(p) + '</div>';
                }).join('')
                : '<div class="col-12"><div class="catalog_empty">Зараз немає товарів зі знижкою. <a href="shop.html">Переглянути каталог</a></div></div>');
        });
    }

    function searchSuggest($form) {
        var $input = $form.find('input[name=q]');
        if (!$input.length) return;
        var $box = $('<div class="search_suggest" role="listbox" hidden></div>').appendTo($form.addClass('has_suggest'));
        var timer = null, request = null, last = '';

        function close() { $box.prop('hidden', true).empty(); }

        function render(res, text) {
            var url = 'shop.html?q=' + encodeURIComponent(text), html = '';
            if (res.note === 'layout' || res.note === 'typo') {
                html += '<div class="search_suggest_note">Шукаємо «' + esc(res.query) + '»</div>';
                url = 'shop.html?q=' + encodeURIComponent(res.query);
            }
            html += $.map(res.categories || [], function (c) {
                return '<a class="search_suggest_item is-category" role="option" href="shop.html?cat=' + c.id + '">' +
                    '<i class="fas fa-folder" aria-hidden="true"></i><span>' + esc(c.name) + '</span><small>Категорія</small></a>';
            }).join('');
            html += $.map(res.items || [], function (p) {
                return '<a class="search_suggest_item" role="option" href="product.html?id=' + p.id + '">' +
                    '<img src="' + esc(imageUrl(p)) + '" alt="" loading="lazy">' +
                    '<span>' + esc(p.name) + '</span><strong>' + money(p.price) + '</strong></a>';
            }).join('');
            if (!res.items.length && !(res.categories || []).length) {
                html += '<div class="search_suggest_note">Нічого не знайдено</div>';
            } else if (res.total > res.items.length) {
                html += '<a class="search_suggest_all" href="' + url + '">Усі результати (' + res.total + ')</a>';
            }
            $box.html(html).prop('hidden', false);
        }

        function load() {
            var text = $.trim($input.val());
            if (text.length < 2) { last = ''; return close(); }
            if (text === last) return;
            last = text;
            if (request) request.abort();
            request = api({ action: 'suggest', q: text }).done(function (res) {
                if ($.trim($input.val()) === text && $input.is(':focus')) render(res, text);
            });
        }

        $input.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(load, 200);
        }).on('focus', function () {
            if ($box.children().length) $box.prop('hidden', false); else { last = ''; load(); }
        }).on('keydown', function (e) {
            var $items = $box.find('a'), index = $items.index($items.filter('.is-active'));
            if (e.key === 'Escape') return close();
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Enter') return;
            if ($box.prop('hidden') || !$items.length) return;
            if (e.key === 'Enter') {
                if (index === -1) return;
                e.preventDefault();
                location.href = $items.eq(index).attr('href');
                return;
            }
            e.preventDefault();
            index = e.key === 'ArrowDown' ? (index + 1) % $items.length : (index <= 0 ? $items.length : index) - 1;
            $items.removeClass('is-active').eq(index).addClass('is-active');
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest($form).length) $box.prop('hidden', true);
        });
    }

    function linkMenuCategories() {

        var $mobile = $('.devices_nav .down_nav').filter(function () {
            return /магазин/i.test($(this).find('.title_down a').first().text());
        }).children('.down_item');

        $mobile.find('a[href="index.html"]').attr('href', 'shop.html');
        var $menu = $('.category-shop-menu-list').add($mobile);
        if (!$menu.length) return;
        api({ action: 'categories' }).done(function (res) {
            var byName = {};
            $.each(res.categories, function (_, c) { byName[c.name.toLowerCase()] = c.id; });
            var counts = {};
            $.each(res.categories, function (_, c) { counts[c.id] = c.count; });
            var link = function () {
                $menu.find('a').each(function () {
                    var id = byName[$.trim($(this).text()).toLowerCase()];
                    if (!id) return;
                    $(this).attr('href', 'shop.html?cat=' + id);

                    if (!counts[id]) {
                        var $title = $(this).closest('.title');
                        ($title.length ? $title.closest('.down_two') : $(this).closest('li')).hide();
                    }
                });
            };

            if (window.OmegaI18n) window.OmegaI18n.withOriginal(link); else link();
        });
    }

    window.OmegaShop = {
        api: api,
        Cart: Cart,
        loadCart: loadCart,
        productCard: productCard,
        productUrl: productUrl,
        imageUrl: imageUrl,
        stockLabel: stockLabel,
        discount: discount,
        canBuy: canBuy,
        buyButton: buyButton,
        money: money,
        plural: plural,
        esc: esc
    };
})(jQuery);
