(function ($) {
    var API = 'api/services.php';

    function norm(s) {
        return String(s || '').toLowerCase().replace(/i/g, 'і').replace(/\s+/g, ' ').replace(/[.\s]+$/, '').trim();
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function url(s) { return 'service.html?id=' + s.id; }

    var loaded = null;
    function load() {
        if (!loaded) loaded = $.ajax({ url: API, data: { action: 'list' }, dataType: 'json', cache: false })
            .then(function (res) { return res.services || []; });
        return loaded;
    }

    function tree(list) {
        var top = list.filter(function (s) { return s.parent_id === null; });
        var kids = function (id) { return list.filter(function (s) { return s.parent_id === id; }); };
        return { top: top, kids: kids };
    }

    function imageOf(s, list) {
        if (s.image) return s.image;
        var parent = list.filter(function (p) { return p.id === s.parent_id; })[0];
        return parent && parent.image ? parent.image : 'icons/no-image.svg';
    }

    function card(s, list) {
        return '<div class="col-12 col-sm-6 col-md-4 col-lg-3 item_product">' +
            '<a class="service_card" href="' + url(s) + '">' +
                '<div class="service_card_img"><img src="' + esc(imageOf(s, list)) + '" alt="" loading="lazy"></div>' +
                '<div class="service_card_body"><h3>' + esc(s.name) + '</h3>' +
                    (s.summary ? '<p>' + esc(s.summary) + '</p>' : '') +
                    '<span class="service_card_more">Детальніше <i class="fas fa-arrow-right"></i></span>' +
                '</div>' +
            '</a>' +
        '</div>';
    }

    function linkMenus(list) {
        var t = tree(list);
        var topByName = {};
        t.top.forEach(function (s) { topByName[norm(s.name)] = s; });

        var containers = $('li.down').filter(function () { return norm($(this).children('a').first().text()) === 'послуги'; }).find('.down_block')
            .add($('.down_nav').filter(function () { return norm($(this).find('.title_down a').first().text()) === 'послуги'; }).find('> .down_item'));
        containers.each(function () {
            var section = null, last = null;
            $(this).find('a').each(function () {
                var name = norm($(this).text());
                if (topByName[name]) {
                    section = last = topByName[name];
                    $(this).attr('href', url(section));
                } else if (section) {
                    var child = t.kids(section.id).filter(function (c) { return norm(c.name) === name; })[0];

                    if (child) $(this).attr('href', url(last = child));
                }
            });
        });

        $('a').each(function () {
            var $a = $(this);
            if ($a.closest('.down_block, .down_nav, #serviceView, #servicesGrid').length) return;
            var href = $a.attr('href') || '';
            if (!/^(services|repair|index)\.html$/.test(href)) return;
            var s = topByName[norm($a.text())];
            if (s) $a.attr('href', url(s));
        });
    }

    function renderGrid(list) {
        var $grid = $('#servicesGrid');
        if (!$grid.length) return;
        var t = tree(list);
        $grid.html(t.top.map(function (s) { return card(s, list); }).join('') ||
            '<p class="col-12 text-gray">Послуги ще не додані.</p>');
    }

    $(function () {
        load().done(function (list) {

            if (window.OmegaI18n) window.OmegaI18n.withOriginal(function () { linkMenus(list); });
            else linkMenus(list);
            renderGrid(list);
        });
    });

    window.OmegaServices = { load: load, tree: tree, card: card, url: url, esc: esc, imageOf: imageOf, norm: norm };
})(jQuery);
