jQuery(function ($) {
    var S = window.OmegaShop;

    function stars(n) {
        var html = '';
        for (var i = 1; i <= 5; i++) html += '<i class="fas fa-star ' + (i <= n ? 'bg_yello' : 'bg_gray') + '"></i>';
        return '<span class="review_stars" aria-label="Оцінка ' + n + ' з 5">' + html + '</span>';
    }

    function date(utc) {
        return new Date(utc.replace(' ', 'T') + 'Z').toLocaleDateString('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    $.getJSON('api/reviews.php', { action: 'published' }).done(function (res) {
        $('.service_number').text(res.count);
        $('.commet_number h5').first().text(res.count ? res.average.toString().replace('.', ',') + ' з 5' : 'Ще немає оцінок');
        $('#rating h5').first().text(res.count ? res.average.toString().replace('.', ',') + ' з 5' : 'Ще немає оцінок');

        $('#rating .result_item').each(function (i) {
            var n = res.by_stars[5 - i] || 0;
            $(this).find('.text_star a').text(n + ' ' + S.plural(n, 'відгук', 'відгуки', 'відгуків'));
        });

        if (!res.reviews.length) {
            $('#reviewsList').html('<div class="review_empty">Поки що немає відгуків. Будьте першим — натисніть «Залишити відгук».</div>');
            return;
        }
        $('#reviewsList').html(res.reviews.map(function (r) {
            return '<article class="review_card">' +
                '<header><strong>' + S.esc(r.name) + '</strong>' + stars(r.stars) +
                    (r.verified ? '<span class="review_badge" title="Відгук за посиланням після виконаного замовлення">' +
                        (r.order_type === 'repair' ? 'Ремонт у сервісі' : 'Покупка в магазині') + '</span>' : '') +
                    '<time>' + date(r.created_at) + '</time></header>' +
                '<p>' + S.esc(r.text).replace(/\n/g, '<br>') + '</p>' +
                (r.pros ? '<p class="review_pros"><b>Переваги:</b> ' + S.esc(r.pros) + '</p>' : '') +
                (r.cons ? '<p class="review_cons"><b>Недоліки:</b> ' + S.esc(r.cons) + '</p>' : '') +
            '</article>';
        }).join(''));
    }).fail(function () {
        $('#reviewsList').html('<div class="review_empty">Не вдалося завантажити відгуки.</div>');
    });
});
