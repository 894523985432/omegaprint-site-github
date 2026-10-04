jQuery(function ($) {
    var API = 'auth.php';
    var forms = {
        signin: { $form: $('#form-signin'), action: 'login',    prefix: 'signin' },
        signup: { $form: $('#form-signup'), action: 'register', prefix: 'signup' }
    };

    $.each(forms, function (_, f) {
        if (f.$form.data('yiiActiveForm')) f.$form.yiiActiveForm('destroy');
        f.$form.off('submit');
    });
    $('#form-signup .field-signup-recaptcha').remove();

    function clearErrors(f) {
        f.$form.find('.form-group').removeClass('has-error');
        f.$form.find('.help-block-error').text('');
    }

    function showErrors(f, errors, message) {
        $.each(errors || {}, function (field, text) {
            var $group = f.$form.find('.field-' + f.prefix + '-' + field);
            $group.addClass('has-error').find('.help-block-error').text(text);
        });
        if (message) alert(message);
    }

    $.each(forms, function (_, f) {
        f.$form.on('submit', function (e) {
            e.preventDefault();
            clearErrors(f);
            var $btn = f.$form.find('button[type=submit]').prop('disabled', true);

            $.ajax({ url: API + '?action=' + f.action, method: 'POST', data: f.$form.serialize(), dataType: 'json' })
                .done(function (res) {
                    if (!res.ok) return showErrors(f, res.errors, res.message);
                    f.$form.closest('.modal').modal('hide');
                    f.$form[0].reset();
                    renderUser(res.user);
                })
                .fail(function (xhr) {
                    var res = xhr.responseJSON || {};
                    showErrors(f, res.errors, res.message || (res.errors ? '' : 'Не вдалося зв\'язатися із сервером.'));
                })
                .always(function () { $btn.prop('disabled', false); });
        });
    });

    var $recovery = $('#recovery-1 form');
    if ($recovery.data('yiiActiveForm')) $recovery.yiiActiveForm('destroy');
    $recovery.off('submit').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this), $err = $form.find('.help-block-error').text(''), $btn = $form.find('button[type=submit]').prop('disabled', true);
        $form.find('.form-group').removeClass('has-error');
        $.ajax({ url: API + '?action=reset_request', method: 'POST', dataType: 'json', data: { email: $.trim($form.find('input[type=text], input[type=email]').val()) } })
            .always(function () { $btn.prop('disabled', false); })
            .done(function (res) {
                $form.find('.modal-body').html('<p>' + $('<span>').text(res.message).html() + '</p>');
                $btn.hide();
            })
            .fail(function (xhr) {
                var res = xhr.responseJSON || {};
                $err.text((res.errors && res.errors.email) || res.message || "Не вдалося зв'язатися із сервером.").closest('.form-group').addClass('has-error');
            });
    });

    var $profile = $('.profile_control').closest('.click_control');
    var guestProfileHtml = $profile.html();
    var $mobile = $('.sign_device ul');
    var guestMobileHtml = $mobile.html();

    function renderUser(user) {
        $(document).trigger('omega:auth', [user]);
        if (!user) {
            $profile.html(guestProfileHtml);
            $mobile.html(guestMobileHtml);
            return;
        }
        var name = $('<span>').text(user.username).html();
        $profile.html(
            '<i class="fas fa-user fa-lg"></i>' + name +
            '<ul class="control_item profile_control" style="display: none;">' +
                '<li class="lang"><a href="account.html">Особистий кабінет</a></li>' +
                (user.is_admin ? '<li class="lang"><a href="admin.html">Адмін-панель</a></li>' : '') +
                '<li class="lang"><a href="#" class="js-logout">Вийти</a></li>' +
            '</ul>'
        );
        $mobile.html('<li><a href="account.html">' + name + ' · Особистий кабінет</a></li>' +
            (user.is_admin ? '<li><a href="admin.html">Адмін-панель</a></li>' : '') +
            '<li><span class="js-logout">Вийти</span></li>');
    }

    $(document).on('click', '.js-logout', function (e) {
        e.preventDefault();
        $.post(API + '?action=logout').always(function () { renderUser(null); });
    });

    function renderCounters(c) {
        if (!c) return;
        $('.repairs li span').each(function () {
            var text = $(this).text();
            if (/^Відремонтовано|^Отремонтировано|^Repaired/.test(text)) $(this).text('Відремонтовано - ' + c.repaired);
            else if (/^Заправлено|^Refilled/.test(text)) $(this).text('Заправлено - ' + c.refilled);
        });
    }

    $.getJSON(API + '?action=me').done(function (res) {
        if (!res.ok) return;
        renderCounters(res.counters);
        if (res.user) renderUser(res.user);
        else $(document).trigger('omega:auth', [null]);
    });
});
