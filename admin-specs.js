(function () {
    'use strict';

    var A = window.AdminCore, $ = A.$, $$ = A.$$, esc = A.esc;
    var attrs = [];
    var TYPES = { 'enum': 'Список', number: 'Число', bool: 'Так / Ні' };

    function categoryOptions(first) {
        return '<option value="">' + first + '</option>' + A.flatTree(null, 0).map(function (x) {
            return '<option value="' + x.cat.id + '">' + '— '.repeat(x.depth) + esc(x.cat.name) + '</option>';
        }).join('');
    }

    function categoryPath(id) {
        var path = [], cats = A.categories();
        while (id) {
            path.push(id);
            var c = cats.filter(function (x) { return x.id === id; })[0];
            id = c ? c.parent_id : null;
        }
        return path;
    }

    function load() {
        return A.adminPost('spec_list', new FormData()).then(function (res) {
            attrs = res.attrs || [];
            render();
        });
    }

    function render() {
        var cat = +$('#specCat').value || 0;
        var path = cat ? categoryPath(cat) : null;
        var list = attrs.filter(function (a) { return !path || a.category_id === null || path.indexOf(a.category_id) !== -1; });
        $('#specRows').innerHTML = list.map(function (a) {
            return '<tr>' +
                '<td><strong>' + esc(a.name) + '</strong>' + (a.unit ? ', ' + esc(a.unit) : '') +
                    (a.filterable ? '' : ' <span class="badge off">не у фільтрах</span>') + '</td>' +
                '<td>' + (a.category_id === null ? '<span class="badge">Усі товари</span>' : esc(A.categoryName(a.category_id))) + '</td>' +
                '<td>' + TYPES[a.type] + '</td>' +
                '<td class="num">' + a.filled + ' тов.</td>' +
                '<td class="spec_help_cell">' + (a.help ? esc(a.help) : '<span class="muted">—</span>') + '</td>' +
                '<td class="actions">' +
                    '<button type="button" class="btn small" data-spec-edit="' + a.id + '">Змінити</button> ' +
                    '<button type="button" class="btn small danger" data-spec-del="' + a.id + '">Видалити</button>' +
                '</td>' +
            '</tr>';
        }).join('') || '<tr><td colspan="6" class="muted">Для цієї категорії характеристик ще немає.</td></tr>';
    }

    function openDialog(a) {
        var form = $('#specForm');
        form.reset();
        A.clearErrors(form);
        $('#specDialogTitle').textContent = a ? 'Редагувати характеристику' : 'Нова характеристика';
        form.elements.category_id.innerHTML = categoryOptions('Усі товари');
        form.elements.id.value = a ? a.id : '';
        form.elements.name.value = a ? a.name : '';
        form.elements.unit.value = a ? a.unit : '';
        form.elements.type.value = a ? a.type : 'enum';
        form.elements.help.value = a ? a.help : '';
        form.elements.sort.value = a ? a.sort : 0;
        form.elements.filterable.checked = a ? a.filterable : true;
        form.elements.category_id.value = a ? (a.category_id || '') : ($('#specCat').value || '');
        $('#specDialog').showModal();
        form.elements.name.focus();
    }

    $('#specCat').addEventListener('change', render);
    $('#addSpecBtn').addEventListener('click', function () { openDialog(null); });
    $('#specRows').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var a = attrs.filter(function (x) { return x.id === +(btn.dataset.specEdit || btn.dataset.specDel); })[0];
        if (!a) return;
        if (btn.dataset.specEdit) openDialog(a);
        if (btn.dataset.specDel) {
            var note = a.filled ? '\nЇї значення буде видалено в ' + a.filled + ' товарах.' : '';
            if (!confirm('Видалити характеристику «' + a.name + '»?' + note)) return;
            A.adminPost('spec_delete', A.formData({ id: a.id })).then(function (res) {
                if (!res.ok) return A.toast(res.message || 'Не вдалося видалити.', true);
                A.toast('Характеристику видалено');
                load();
            });
        }
    });

    $('#specForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        A.clearErrors(form);
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        A.adminPost('spec_save', new FormData(form)).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return A.showErrors(form, res, '#specError');
            $('#specDialog').close();
            A.toast('Характеристику збережено');
            load();
        });
    });

    document.addEventListener('admin:tab', function (e) {
        if (e.detail !== 'specs') return;
        var current = $('#specCat').value;
        $('#specCat').innerHTML = categoryOptions('Усі категорії');
        $('#specCat').value = current;
        load();
    });

    function showCounters(res) {
        if (!res.ok) return A.toast(res.message || 'Не вдалося зберегти.', true);
        var form = $('#countersForm');
        form.elements.repaired.value = res.shown.repaired;
        form.elements.refilled.value = res.shown.refilled;
        $('#countersShown').textContent = 'Зараз на сайті: ' + res.shown.repaired + ' / ' + res.shown.refilled;
    }
    document.addEventListener('admin:tab', function (e) {
        if (e.detail === 'statuses') A.adminPost('counters', new FormData()).then(showCounters);
    });
    $('#countersForm').addEventListener('submit', function (e) {
        e.preventDefault();
        A.adminPost('counters_save', new FormData(this)).then(function (res) {
            showCounters(res);
            if (res.ok) A.toast('Лічильники збережено');
        });
    });

    function loadSynonyms() {
        A.adminPost('search_synonyms', new FormData()).then(function (res) {
            if (!res.ok) return;
            $('#synonymForm').elements.text.value = res.text;
            $('#searchWarning').textContent = res.fulltext ? '' :
                'На цьому сервері недоступний повнотекстовий пошук — каталог шукає лише за точним входженням у назву й артикул.';
        });
    }

    function saveSynonyms(text) {
        A.adminPost('search_synonyms_save', A.formData({ text: text })).then(function (res) {
            if (!res.ok) return A.toast(res.message || 'Не вдалося зберегти.', true);
            $('#synonymForm').elements.text.value = res.text;
            A.toast('Синоніми збережено');
            testSearch();
        });
    }

    var testTimer;
    function testSearch() {
        var q = $('#searchTest').value.trim();
        if (q.length < 2) { $('#searchTestRows').innerHTML = ''; $('#searchTestInfo').textContent = ''; return; }
        A.getJSON('api/shop.php?action=products&per=10&q=' + encodeURIComponent(q)).then(function (res) {
            var note = res.search_note === 'layout' ? ' (виправлено розкладку: «' + res.search_query + '»)'
                : res.search_note === 'typo' ? ' (виправлено одруківку: «' + res.search_query + '»)'
                : res.search_note === 'partial' ? ' (усіх слів разом немає — показано часткові збіги)' : '';
            $('#searchTestInfo').textContent = 'Знайдено: ' + res.total + note;
            $('#searchTestRows').innerHTML = (res.items || []).map(function (p) {
                return '<tr><td>' + esc(p.name) + '<span class="sku">Арт. ' + esc(p.sku) + '</span></td><td class="num">' + A.money(p.price) + '</td></tr>';
            }).join('');
        });
    }

    $('#synonymForm').addEventListener('submit', function (e) {
        e.preventDefault();
        saveSynonyms(this.elements.text.value);
    });
    $('#synonymReset').addEventListener('click', function () {
        if (confirm('Замінити ваш список стандартним?')) saveSynonyms('');
    });
    $('#searchTest').addEventListener('input', function () {
        clearTimeout(testTimer);
        testTimer = setTimeout(testSearch, 300);
    });
    document.addEventListener('admin:tab', function (e) { if (e.detail === 'search') loadSynonyms(); });

    $('#productInfoBtn').addEventListener('click', function () {
        A.adminPost('product_info', new FormData()).then(function (res) {
            if (!res.ok) return A.toast(res.message || 'Не вдалося завантажити.', true);
            var form = $('#productInfoForm');
            form.elements.payment.value = res.info.payment;
            form.elements.warranty.value = res.info.warranty;
            $('#productInfoError').textContent = '';
            $('#productInfoDialog').showModal();
        });
    });
    $('#productInfoForm').addEventListener('submit', function (e) {
        e.preventDefault();
        A.adminPost('product_info_save', new FormData(this)).then(function (res) {
            if (!res.ok) { $('#productInfoError').textContent = res.message || 'Не вдалося зберегти.'; return; }
            $('#productInfoDialog').close();
            A.toast('Плашку збережено');
        });
    });

    var productId = 0, specsRequest = 0;

    function field(s) {
        var name = 'spec[' + s.id + ']';
        var label = esc(s.name) + (s.unit ? ', ' + esc(s.unit) : '');
        var missing = s.value === null;
        var input;
        if (s.type === 'bool') {
            input = '<select name="' + name + '">' +
                '<option value="">— не вказано —</option>' +
                '<option value="1"' + (s.value === '1' ? ' selected' : '') + '>Так</option>' +
                '<option value="0"' + (s.value === '0' ? ' selected' : '') + '>Ні</option>' +
            '</select>';
        } else if (s.type === 'number') {
            input = '<input type="text" inputmode="decimal" name="' + name + '" value="' + esc(s.value || '') + '">';
        } else {
            input = '<input type="text" name="' + name + '" maxlength="120" list="specList' + s.id + '" value="' + esc(s.value || '') + '">' +
                '<datalist id="specList' + s.id + '">' + s.options.map(function (o) { return '<option value="' + esc(o) + '">'; }).join('') + '</datalist>';
        }
        return '<label class="' + (missing ? 'spec_needs' : '') + '"' + (s.help ? ' title="' + esc(s.help) + '"' : '') + '>' + label +
            (missing ? ' <small>потрібна інформація</small>' : '') + input + '</label>';
    }

    function loadProductSpecs() {
        var form = $('#productForm');
        var token = ++specsRequest;

        var typed = {};
        $$('[name^="spec["]', form).forEach(function (el) { if (el.value !== '') typed[el.name] = el.value; });
        A.adminPost('product_specs', A.formData({ id: productId, category_id: form.elements.category_id.value })).then(function (res) {
            if (token !== specsRequest) return;
            var specs = res.specs || [];
            $('#productSpecs').hidden = !specs.length;
            $('#productSpecsGrid').innerHTML = specs.map(field).join('');
            Object.keys(typed).forEach(function (name) {
                var el = form.elements[name];
                if (el) el.value = typed[name];
            });
        });
    }

    document.addEventListener('admin:product-form', function (e) {
        productId = e.detail ? e.detail.id : 0;
        $('#productSpecsGrid').innerHTML = '';
        $('#productSpecs').hidden = true;
        loadProductSpecs();
    });
    $('#productForm [name=category_id]').addEventListener('change', loadProductSpecs);
})();
