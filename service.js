jQuery(function ($) {
    var SV = window.OmegaServices;
    var esc = SV.esc;
    var $view = $('#serviceView');
    var params = new URLSearchParams(location.search);
    var id = parseInt(params.get('id'), 10);
    var slug = params.get('slug');

    function money(n) {
        return Number(n).toLocaleString('uk-UA', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' грн';
    }

    function formatText(text) {
        return String(text || '').replace(/\r\n?/g, '\n').trim().split(/\n\s*\n/).map(function (block) {
            var lines = block.split('\n');
            if (lines.every(function (l) { return /^\s*[-•]\s+/.test(l); })) {
                return '<ul class="service_list">' + lines.map(function (l) {
                    return '<li>' + esc(l.replace(/^\s*[-•]\s+/, '')) + '</li>';
                }).join('') + '</ul>';
            }
            return '<p>' + esc(block).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function notFound() {
        $view.html('<div class="catalog_empty">Послугу не знайдено. <a href="services.html">Усі послуги</a></div>');
        document.title = 'Послугу не знайдено | OmegaPrint - Сервісний центр';
    }

    SV.load().done(function (list) {
        var s = list.filter(function (x) { return slug ? x.slug === slug : x.id === id; })[0];
        if (!s) return notFound();
        var t = SV.tree(list);
        var parent = list.filter(function (x) { return x.id === s.parent_id; })[0] || null;
        var children = t.kids(s.id);
        var siblings = parent ? t.kids(parent.id).filter(function (x) { return x.id !== s.id; }) : [];

        $('#serviceBreadcrumb').html('<li><a href="index.html">Головна</a></li><li><a href="services.html">Послуги</a></li>' +
            (parent ? '<li><a href="' + SV.url(parent) + '">' + esc(parent.name) + '</a></li>' : '') +
            '<li aria-current="page"><a href="' + SV.url(s) + '">' + esc(s.name) + '</a></li>');

        var diag = s.diag_price != null
            ? '<strong>Безкоштовно</strong> при ремонті<br><span>' + money(s.diag_price) + ' — у разі відмови від ремонту</span>'
            : '<strong>Безкоштовно</strong> при ремонті<br><span>у разі відмови від ремонту — вартість уточнюйте</span>';
        var from = s.price_from != null ? '<strong>від ' + money(s.price_from) + '</strong><br><span>точна ціна — після діагностики</span>'
                                        : '<strong>За результатами діагностики</strong><br><span>погоджуємо до початку робіт</span>';
        var term = s.term ? '<strong>' + esc(s.term) + '</strong><br><span>залежить від наявності запчастин</span>'
                          : '<strong>Узгоджується</strong><br><span>після діагностики</span>';

        var prices = (s.prices || []).length
            ? '<section class="service_block"><h2>Ціни на роботи</h2><table class="service_prices"><tbody>' +
                s.prices.map(function (p) {
                    return '<tr><td>' + esc(p.name) + '</td><td>' + esc(p.price) + (/\d\s*$/.test(p.price) ? ' грн' : '') + '</td></tr>';
                }).join('') + '</tbody></table>' +
                '<p class="service_note">Остаточна вартість залежить від несправності та запчастин і погоджується з вами до початку ремонту.</p></section>'
            : '';

        var hidden = !s.visible ? '<div class="notice_admin">Цю послугу приховано — її бачать лише адміністратори.</div>' : '';

        $view.html(hidden +
            '<div class="service_hero">' +
                '<div class="service_hero_img"><img src="' + esc(SV.imageOf(s, list)) + '" alt="' + esc(s.name) + '"></div>' +
                '<div class="service_hero_text">' +
                    '<h1 class="title">' + esc(s.name) + '</h1>' +
                    (s.summary ? '<p class="service_summary">' + esc(s.summary) + '</p>' : '') +
                    '<div class="service_cta">' +
                        '<button type="button" class="service_btn primary" data-toggle="modal" data-target="#call_back">Зателефонуйте мені</button>' +
                        '<button type="button" class="service_btn" data-toggle="modal" data-target="#send_service" data-service="' + esc(s.name) + '">Написати нам</button>' +
                    '</div>' +
                    '<p class="service_phones">Або телефонуйте: <a href="tel:+380955011970">+380 (95) 501-19-70</a>, <a href="tel:+380503520540">+380 (50) 352-05-40</a></p>' +
                '</div>' +
            '</div>' +
            '<div class="service_facts">' +
                '<div class=\"service_fact\"><span class=\"fact_icon\"><i class=\"fas fa-search\"></i></span><h3>Діагностика</h3><p>' + diag + '</p></div>' +
                '<div class=\"service_fact\"><span class=\"fact_icon\"><i class=\"fas fa-credit-card\"></i></span><h3>Вартість ремонту</h3><p>' + from + '</p></div>' +
                '<div class=\"service_fact\"><span class=\"fact_icon\"><i class=\"fas fa-clock\"></i></span><h3>Терміни</h3><p>' + term + '</p></div>' +
                '<div class=\"service_fact\"><span class=\"fact_icon\"><i class=\"fas fa-shield-alt\"></i></span><h3>Гарантія</h3><p><strong>3 місяці</strong><br><span>на всі виконані роботи</span></p></div>' +
            '</div>' +
            (s.description ? '<section class="service_block"><h2>Опис послуги</h2><div class="service_text">' + formatText(s.description) + '</div></section>' : '') +
            prices +
            (children.length ? '<section class="service_block plain"><h2>Що ремонтуємо</h2><div class="row">' +
                children.map(function (c) { return SV.card(c, list); }).join('') + '</div></section>' : '') +
            '<section class="service_callout">' +
                '<div><h2>Потрібен ремонт?</h2><p>Залиште заявку — ми зателефонуємо, проконсультуємо і назвемо вартість.</p></div>' +
                '<div class="service_cta">' +
                    '<button type="button" class="service_btn light" data-toggle="modal" data-target="#call_back">Зателефонуйте мені</button>' +
                    '<button type="button" class="service_btn outline" data-toggle="modal" data-target="#send_service" data-service="' + esc(s.name) + '">Написати нам</button>' +
                '</div>' +
            '</section>' +
            (siblings.length ? '<section class="service_block plain"><h2>Інші послуги розділу «' + esc(parent.name) + '»</h2><div class="service_links">' +
                siblings.map(function (c) { return '<a href="' + SV.url(c) + '">' + esc(c.name) + '</a>'; }).join('') + '</div></section>' : '')
        );
    }).fail(notFound);

    $(document).on('click', '[data-target="#send_service"][data-service]', function () {
        var $device = $('#repair-device');
        if ($device.length && !$device.val()) $device.val($(this).data('service'));
    });
});
