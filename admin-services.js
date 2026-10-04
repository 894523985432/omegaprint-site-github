(function () {
    'use strict';

    var A = window.AdminCore;
    var $ = A.$, $$ = A.$$, esc = A.esc, toast = A.toast;
    var API = 'api/services.php';
    var services = [];

    function post(action, fd) {
        return A.apiPost(API + '?action=' + action, fd);
    }

    function kids(id) {
        return services.filter(function (s) { return s.parent_id === id; });
    }

    function money(n) {
        return n == null ? '—' : A.money(n) + ' грн';
    }

    function load() {
        return A.getJSON(API + '?action=list').then(function (res) {
            services = res.services || [];
            render();
        });
    }

    function render() {
        function branch(parentId) {
            return kids(parentId).map(function (s) {
                var sub = kids(s.id);
                var facts = [
                    'діагностика: ' + money(s.diag_price),
                    'ремонт від: ' + money(s.price_from),
                    'терміни: ' + (s.term || '—')
                ].join(' · ');
                var empty = s.diag_price == null || !s.term;
                return '<li>' +
                    '<div class="cat_row">' +
                        '<img class="svc_thumb" src="' + esc(s.image || 'icons/no-image.svg') + '" alt="">' +
                        '<span class="name">' + esc(s.name) +
                            (!s.visible ? ' <span class="badge off">Приховано</span>' : '') +
                            '<span class="sku' + (empty ? ' warn_text' : '') + '">' + esc(facts) + '</span></span>' +
                        (s.parent_id === null ? '<button type="button" class="btn small" data-svc-add="' + s.id + '">+ Підрозділ</button>' : '') +
                        '<a class="btn small" href="service.html?id=' + s.id + '" target="_blank" rel="noopener">Сторінка ↗</a>' +
                        '<button type="button" class="btn small" data-svc-edit="' + s.id + '">Змінити</button>' +
                        '<button type="button" class="btn small danger" data-svc-del="' + s.id + '">Видалити</button>' +
                    '</div>' +
                    (sub.length ? '<ul>' + branch(s.id) + '</ul>' : '') +
                '</li>';
            }).join('');
        }
        $('#serviceTree').innerHTML = branch(null) || '<li class="muted">Послуг ще немає.</li>';
    }

    function priceRow(row) {
        var div = document.createElement('div');
        div.className = 'price_row';
        div.innerHTML = '<input type="text" class="p_name" placeholder="Назва роботи" maxlength="200" value="' + esc(row ? row.name : '') + '">' +
            '<input type="text" class="p_price" placeholder="Ціна, напр. 350 або від 500" maxlength="60" value="' + esc(row ? row.price : '') + '">' +
            '<button type="button" class="btn small danger" aria-label="Видалити рядок">×</button>';
        div.querySelector('button').addEventListener('click', function () { div.remove(); });
        $('#priceRows').appendChild(div);
        return div;
    }

    function open(s, parentId) {
        var form = $('#serviceForm'), f = form.elements;
        form.reset();
        A.clearErrors(form);
        $('#serviceDialogTitle').textContent = s ? 'Редагувати послугу' : (parentId ? 'Новий підрозділ' : 'Новий розділ');

        f.parent_id.innerHTML = '<option value="">— Розділ верхнього рівня —</option>' + kids(null)
            .filter(function (t) { return !s || t.id !== s.id; })
            .map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('');
        f.id.value = s ? s.id : '';
        f.name.value = s ? s.name : '';
        f.parent_id.value = s ? (s.parent_id || '') : (parentId || '');
        f.parent_id.disabled = !!(s && kids(s.id).length);
        f.sort.value = s ? s.sort : 0;
        f.summary.value = s ? s.summary : '';
        f.description.value = s ? s.description : '';
        f.diag_price.value = s && s.diag_price != null ? s.diag_price : '';
        f.price_from.value = s && s.price_from != null ? s.price_from : '';
        f.term.value = s ? s.term : '';
        f.visible.checked = s ? s.visible : true;
        $('#priceRows').innerHTML = '';
        ((s && s.prices) || []).forEach(priceRow);
        if (!s || !s.prices.length) priceRow(null);
        $('#servicePhoto').src = s && s.image ? s.image : 'icons/no-image.svg';
        $('#serviceRemoveImage').hidden = !(s && s.image);
        var link = $('#serviceOpenLink');
        link.hidden = !s;
        if (s) link.href = 'service.html?id=' + s.id;
        $('#serviceDialog').showModal();
        f.name.focus();
    }

    $('#addPriceRow').addEventListener('click', function () { priceRow(null).querySelector('.p_name').focus(); });
    $('#serviceForm').elements.image.addEventListener('change', function () {
        if (this.files[0]) $('#servicePhoto').src = URL.createObjectURL(this.files[0]);
    });
    $('#addServiceBtn').addEventListener('click', function () { open(null); });

    $('#serviceTree').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var find = function (id) { return services.filter(function (s) { return s.id === +id; })[0]; };
        if (btn.dataset.svcAdd) open(null, +btn.dataset.svcAdd);
        if (btn.dataset.svcEdit) open(find(btn.dataset.svcEdit));
        if (btn.dataset.svcDel) {
            var s = find(btn.dataset.svcDel);
            if (!confirm('Видалити «' + s.name + '»? Сторінка послуги зникне з сайту.')) return;
            post('delete', A.formData({ id: s.id })).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
                toast('Послугу видалено');
                load();
            });
        }
    });

    $('#serviceForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        A.clearErrors(form);
        var fd = new FormData(form);
        if (form.elements.parent_id.disabled) fd.set('parent_id', '');
        fd.set('prices', JSON.stringify($$('#priceRows .price_row').map(function (row) {
            return { name: row.querySelector('.p_name').value.trim(), price: row.querySelector('.p_price').value.trim() };
        })));
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        var note = '';
        A.prepareImage(fd, form.elements.image).then(function (info) {
            note = info;
            return post('save', fd);
        }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return A.showErrors(form, res, '#serviceError');
            $('#serviceDialog').close();
            toast('Послугу збережено' + (note ? '. ' + note : ''));
            load();
        });
    });

    document.addEventListener('admin:ready', load);
})();
