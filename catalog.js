jQuery(function ($) {
    var S = window.OmegaShop;
    var $form = $('#catalogFilters');
    var $grid = $('#catalogGrid');
    var $info = $('#catalogInfo');
    var $pager = $('#catalogPager');
    var categories = [];
    var FIELDS = ['cat', 'q', 'min', 'max', 'stock', 'sale', 'sort', 'per', 'page'];

    function isSpecKey(k) { return /^f\d+$/.test(k); }

    function readState() {
        var p = new URLSearchParams(location.search), state = {};
        $.each(FIELDS, function (_, k) { if (p.get(k)) state[k] = p.get(k); });
        p.forEach(function (v, k) { if (isSpecKey(k) && v) state[k] = v; });
        return state;
    }

    function writeState(state, push) {
        var p = new URLSearchParams();
        $.each(FIELDS, function (_, k) { if (state[k]) p.set(k, state[k]); });
        $.each(state, function (k, v) { if (isSpecKey(k) && v) p.set(k, v); });
        var url = 'shop.html' + (p.toString() ? '?' + p : '');
        history[push ? 'pushState' : 'replaceState'](state, '', url);
    }

    function fillForm(state) {
        $form.find('[name=cat]').val(state.cat || '');
        $form.find('[name=q]').val(state.q || '');
        $form.find('[name=min]').val(state.min || '');
        $form.find('[name=max]').val(state.max || '');
        $form.find('[name=stock]').prop('checked', !!state.stock);
        $form.find('[name=sale]').prop('checked', !!state.sale);
        $form.find('[name=sort]').val(state.sort || '');
        $form.find('[name=per][value="' + (state.per || '24') + '"]').prop('checked', true);
    }

    function formState() {
        var state = {};
        $.each($form.serializeArray(), function (_, f) {
            if (f.value === '') return;

            if (isSpecKey(f.name) && state[f.name]) state[f.name] += '|' + f.value;
            else state[f.name] = f.value;
        });
        $form.find('.spec_range').each(function () {
            var min = $.trim($(this).find('[data-bound=min]').val()), max = $.trim($(this).find('[data-bound=max]').val());
            if (min !== '' || max !== '') state[$(this).data('key')] = min + '..' + max;
        });
        if (state.per === '24') delete state.per;
        return state;
    }

    var specRequest = null, specScope = null;

    function specValueLabel(f, value) {
        if (f.type === 'bool') return value === '1' ? 'Так' : 'Ні';
        return value + (f.unit ? ' ' + f.unit : '');
    }

    function renderSpecFilters(filters, isAdmin, state) {
        $('#productFilters .spec_filter').remove();
        var html = $.map(filters, function (f) {
            var key = 'f' + f.id, current = state[key] || '', body = '';
            if (f.type === 'number') {
                var range = /^(.*)\.\.(.*)$/.exec(current) || [];
                body = '<div class="catalog_price spec_range" data-key="' + key + '">' +
                    '<input type="number" step="any" data-bound="min" placeholder="від ' + (f.min == null ? '' : f.min) + '" value="' + S.esc(range[1] || '') + '" aria-label="' + S.esc(f.name) + ' від">' +
                    '<span class="dash">–</span>' +
                    '<input type="number" step="any" data-bound="max" placeholder="до ' + (f.max == null ? '' : f.max) + '" value="' + S.esc(range[2] || '') + '" aria-label="' + S.esc(f.name) + ' до">' +
                    '</div>';
            } else {
                var picked = current.split('|');
                body = '<div class="spec_values">' + $.map(f.values, function (v) {
                    return '<label><input type="checkbox" name="' + key + '" value="' + S.esc(v.value) + '"' +
                        (picked.indexOf(v.value) !== -1 ? ' checked' : '') + '> <span>' + S.esc(specValueLabel(f, v.value)) + '</span>' +
                        '<span class="text-gray">' + v.count + '</span></label>';
                }).join('') + '</div>';
            }

            if (isAdmin && f.missing > 0) {
                body += '<label class="spec_missing" title="Бачать лише адміністратори"><input type="checkbox" name="' + key + '" value="__none"' +
                    (current.split('|').indexOf('__none') !== -1 ? ' checked' : '') + '> <span>Потрібна інформація</span>' +
                    '<span class="text-gray">' + f.missing + '</span></label>';
            }
            return '<li class="spec_filter">' +
                '<div class="filter-link"><strong>' + S.esc(f.name) + (f.unit && f.type === 'number' ? ', ' + S.esc(f.unit) : '') + '</strong>' +
                    (f.help ? '<button type="button" class="spec_help" aria-expanded="false" aria-label="Що це означає?">?</button>' : '') + '</div>' +
                (f.help ? '<p class="spec_help_text" hidden>' + S.esc(f.help) + '</p>' : '') +
                '<div class="filter_block">' + body + '</div>' +
            '</li>';
        }).join('');
        $('#catalogSpecsAnchor').before(html);
    }

    function loadSpecFilters(state) {
        var params = { action: 'filters' };
        $.each(['cat', 'q'], function (_, k) { if (state[k]) params[k] = state[k]; });
        var scope = $.param(params);
        if (scope === specScope) return;
        specScope = scope;
        if (specRequest) specRequest.abort();
        specRequest = S.api(params).done(function (res) {
            renderSpecFilters(res.filters || [], !!res.admin, readState());
        });
    }

    $form.on('click', '.spec_help', function () {
        var $text = $(this).closest('.spec_filter').find('.spec_help_text');
        var open = $text.prop('hidden');
        $text.prop('hidden', !open);
        $(this).attr('aria-expanded', open);
    });

    function categoryById(id) {
        for (var i = 0; i < categories.length; i++) if (categories[i].id === +id) return categories[i];
        return null;
    }

    function categoryPath(id) {
        var path = [], c = categoryById(id);
        while (c) { path.unshift(c); c = c.parent_id ? categoryById(c.parent_id) : null; }
        return path;
    }

    function renderCategories(state) {
        var active = +state.cat || 0;
        var openIds = $.map(categoryPath(active), function (c) { return c.id; });

        function branch(parentId) {

            var items = $.grep(categories, function (c) {
                return c.parent_id === parentId && (c.count > 0 || openIds.indexOf(c.id) !== -1);
            });
            if (!items.length) return '';
            return $.map(items, function (c) {
                var kids = $.grep(categories, function (k) { return k.parent_id === c.id && k.count > 0; }).length;
                var isOpen = openIds.indexOf(c.id) !== -1;
                return '<li class="' + (c.id === active ? 'active' : '') + '">' +
                    '<a href="shop.html?cat=' + c.id + '" data-cat="' + c.id + '">' + S.esc(c.name) +
                    ' <span class="text-gray">' + c.count + '</span></a>' +
                    (kids && isOpen ? '<ul>' + branch(c.id) + '</ul>' : '') +
                '</li>';
            }).join('');
        }

        $('#catalogCats').html(
            '<li class="' + (active ? '' : 'active') + '"><a href="shop.html" data-cat="">Усі товари</a></li>' + branch(null)
        );
    }

    function renderHeading(state) {
        var path = state.cat ? categoryPath(state.cat) : [];
        var title = path.length ? path[path.length - 1].name : 'Інтернет-магазин';
        if (state.q) title = 'Пошук: «' + state.q + '»';
        $('#catalogTitle').text(title);

        if (!state.q && path.length) title += ' — купити в Києві, ціни';
        else if (!state.q) title = 'Інтернет-магазин: шредери, ламінатори, різаки, біндери';
        document.title = title + ' | OmegaPrint - Сервісний центр';

        var crumbs = '<li><a href="index.html">Головна</a></li><li><a href="shop.html">Інтернет-магазин</a></li>';
        $.each(path, function (_, c) {
            crumbs += '<li><a href="shop.html?cat=' + c.id + '">' + S.esc(c.name) + '</a></li>';
        });
        $('#catalogBreadcrumb').html(crumbs);
    }

    function renderPager(page, pages) {
        if (pages <= 1) { $pager.empty(); return; }
        var html = '';
        function link(p, label, cls) {
            html += '<button type="button" class="catalog_page ' + (cls || '') + '" data-page="' + p + '"' +
                (p === page ? ' aria-current="page"' : '') + '>' + label + '</button>';
        }
        if (page > 1) link(page - 1, '‹', 'prev');
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || Math.abs(p - page) <= 2) link(p, p, p === page ? 'active' : '');
            else if (Math.abs(p - page) === 3) html += '<span class="catalog_gap">…</span>';
        }
        if (page < pages) link(page + 1, '›', 'next');
        $pager.html(html);
    }

    var request = null;
    function load(state) {
        renderCategories(state);
        renderHeading(state);
        loadSpecFilters(state);
        if (request) request.abort();
        $grid.addClass('is-loading');
        var params = $.extend({ action: 'products' }, state);
        request = S.api(params).done(function (res) {
            if (!res.items.length) {
                $grid.html('<div class="col-12"><div class="catalog_empty">' +
                    (state.q ? 'За запитом «' + S.esc(state.q) + '» нічого не знайдено.' : 'У цьому розділі поки немає товарів.') +
                    ' <a href="shop.html">Переглянути всі товари</a></div></div>');
            } else {
                $grid.html($.map(res.items, function (p) {
                    return '<div class="col-12 col-sm-6 col-md-6 col-lg-4">' + S.productCard(p) + '</div>';
                }).join(''));
            }
            $info.text(res.total ? 'Знайдено ' + res.total + ' ' + S.plural(res.total, 'товар', 'товари', 'товарів') : '');

            var note = '';
            if (res.total && (res.search_note === 'layout' || res.search_note === 'typo')) note = 'Показано результати за запитом «' + res.search_query + '».';
            if (res.total && res.search_note === 'partial') note = 'Товарів з усіма словами запиту немає — показано ті, де є хоча б одне.';
            $('#catalogSearchNote').text(note).prop('hidden', !note);
            renderPager(res.page, res.pages);
        }).fail(function (xhr, status) {
            if (status === 'abort') return;
            $grid.html('<div class="col-12"><div class="catalog_empty">Не вдалося завантажити товари. Спробуйте оновити сторінку.</div></div>');
        }).always(function () {
            $grid.removeClass('is-loading');
        });
    }

    function go(state, push) {
        fillForm(state);
        writeState(state, push);
        load(state);
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        var state = formState();
        delete state.page;
        go(state, true);
        if (window.innerWidth < 992) $('#productFilters').collapse('hide');
    });
    $form.on('change', '[name=sort], [name=per], [name=stock], [name=sale], .spec_filter input[type=checkbox]', function () { $form.trigger('submit'); });

    $('#catalogCats').on('click', 'a[data-cat]', function (e) {
        e.preventDefault();
        var state = formState();
        state.cat = String($(this).data('cat') || '');
        if (!state.cat) delete state.cat;
        delete state.page;

        $.each(state, function (k) { if (isSpecKey(k)) delete state[k]; });
        go(state, true);
    });

    $pager.on('click', '[data-page]', function () {
        var state = formState();
        state.page = String($(this).data('page'));
        if (state.page === '1') delete state.page;
        go(state, true);
        $('html, body').animate({ scrollTop: $('#catalog').offset().top - 20 }, 200);
    });

    $(window).on('popstate', function () {
        var state = readState();
        fillForm(state);
        specScope = null;
        load(state);
    });

    S.api({ action: 'categories' }).always(function (res) {
        categories = (res && res.categories) || [];
        var state = readState();
        fillForm(state);
        load(state);
    });
});
