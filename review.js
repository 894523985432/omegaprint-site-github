jQuery(function ($) {
    var S = window.OmegaShop;
    var token = new URLSearchParams(location.search).get('t') || '';
    var $box = $('#reviewBox');
    var $form = $('#reviewForm');

    function date(utc) {
        return new Date(utc.replace(' ', 'T') + 'Z').toLocaleDateString('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function showForm() {
        $('#reviewLoading').remove();
        $form.prop('hidden', false);
    }

    if (token) {
        $.getJSON('api/reviews.php', { action: 'context', t: token }).done(function (res) {
            if (res.already) {
                $box.html('<div class="review_done"><div class="review_done_icon">✓</div><h2>Ви вже залишили відгук на це замовлення</h2>' +
                    '<p>Дякуємо! <a href="reviews.html">Подивитися відгуки</a></p></div>');
                return;
            }
            var o = res.order;
            $('#reviewOrder').html('<span class="review_order_label">' + S.esc(o.title) + ' №' + o.number + ' від ' + date(o.date) + '</span>' +
                (o.what ? '<strong>' + S.esc(o.what) + '</strong>' : '')).prop('hidden', false);
            if (o.name) $form.find('[name=name]').val(o.name);
            showForm();
        }).fail(function () {
            $('#reviewNotice').text('Посилання недійсне або застаріле, але ви все одно можете залишити відгук.').prop('hidden', false);
            token = '';
            showForm();
        });
    } else {
        showForm();
        loadMyOrders();
    }

    var $pick = $('#orderPick');
    function loadMyOrders() {
        $.getJSON('api/reviews.php', { action: 'my_orders' }).done(function (res) {
            if (!res.logged_in) {
                $pick.html('<p class="order_pick_note">Купували в нас або ремонтували техніку? ' +
                    '<button type="button" class="link_btn" data-toggle="modal" data-target="#sign_in">Увійдіть</button>, ' +
                    'щоб обрати своє замовлення — відгук буде позначено як перевірений.</p>').prop('hidden', false);
                return;
            }
            if (res.name && !$form.find('[name=name]').val()) $form.find('[name=name]').val(res.name);
            if (!res.orders.length) {
                $pick.html('<p class="order_pick_note">Виконаних замовлень без відгуку не знайдено — можна залишити загальний відгук.</p>').prop('hidden', false);
                return;
            }
            $pick.html('<span class="review_label">Про яке замовлення відгук?</span>' +
                res.orders.map(function (o, i) {
                    return '<label class="order_option"><input type="radio" name="pick" value="' + o.type + ':' + o.id + '"' + (i === 0 ? ' checked' : '') + '>' +
                        '<span><strong>' + S.esc(o.title) + '</strong>' + (o.date ? ' · ' + date(o.date) : '') +
                        (o.what ? '<small>' + S.esc(o.what) + '</small>' : '') + '</span></label>';
                }).join('') +
                '<label class="order_option"><input type="radio" name="pick" value=""><span><strong>Загальний відгук</strong><small>без прив\'язки до замовлення</small></span></label>'
            ).prop('hidden', false);
        });
    }
    $(document).on('omega:auth', function (e, user) {
        if (!token && user) loadMyOrders();
    });

    $form.on('change', 'input[name=stars]', function () {
        var labels = ['', 'Погано', 'Так собі', 'Нормально', 'Добре', 'Відмінно'];
        $('#starsLabel').text(labels[$(this).val()]);
        $form.find('.field_stars .err').text('');
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        $form.find('.err').text('');
        var data = $form.serializeArray().filter(function (f) { return f.name !== 'pick'; });
        data.push({ name: 't', value: token });
        var pick = ($form.find('input[name=pick]:checked').val() || '').split(':');
        if (!token && pick[1]) {
            data.push({ name: 'order_type', value: pick[0] }, { name: 'order_id', value: pick[1] });
        }
        var $btn = $form.find('button[type=submit]').prop('disabled', true).text('Надсилаємо…');
        $.ajax({ url: 'api/reviews.php?action=submit', method: 'POST', data: $.param(data), dataType: 'json' })
            .done(function () {
                $box.html('<div class="review_done"><div class="review_done_icon">✓</div><h2>Дякуємо за відгук!</h2>' +
                    '<p>Він з\'явиться на сайті після перевірки модератором.</p><p><a href="index.html">На головну</a></p></div>');
                $('html, body').animate({ scrollTop: $box.offset().top - 120 }, 200);
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                $.each(res.errors || {}, function (field, text) { $form.find('[data-err="' + field + '"]').text(text); });
                if (res.message) $form.find('[data-err="form"]').text(res.message);
                $btn.prop('disabled', false).text('Надіслати відгук');
            });
    });
});
