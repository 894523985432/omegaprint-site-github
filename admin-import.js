(function () {
    'use strict';

    var A = window.AdminCore, $ = A.$, $$ = A.$$, esc = A.esc;

    var FIELDS = [
        { key: 'sku',   label: 'Артикул (код) *',      re: /^(код|артикул|арт\.?|sku|id товар)/i },
        { key: 'name',  label: 'Назва *',              re: /назв|наймен|name/i },
        { key: 'brand', label: 'Виробник',             re: /бренд|виробник|brand|торгова марка/i },
        { key: 'price', label: 'Ціна, грн *',          re: /ррц|роздр|рекоменд|price|^ціна/i, not: /дилер|закуп|опт|знижк|акці/i },
        { key: 'sale',  label: 'Ціна зі знижкою',      re: /знижк|акці|sale|розпрод/i },
        { key: 'stock', label: 'Залишок (кількість)',  re: /залиш|наявн|кільк|stock|склад/i }
    ];
    var DEFAULT_EXCLUDE = 'Ніж, Верхній ніж, Нижній ніж, Марзан, Свердло, Лоток, Перфораційний інструмент, Інструмент, Масло, Пакет, ' +
        'Екран, Запасн, Вал, Килимок, Лезо, Голка, Нитки, Кусачки, Термоклей для, Панель, Комплект ножів, Стіл для, Металевий дріт';

    var state = { rows: [], headerIndex: -1, headers: [], sections: [], settings: {}, skus: {}, fileName: '' };

    function post(action, body) {
        return fetch('api/import.php?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Помилка сервера (' + r.status + ').' }; });
        }).catch(function () { return { ok: false, message: 'Немає з\'єднання із сервером.' }; });
    }

    function filled(row) { return row.filter(function (c) { return c !== ''; }); }

    function findHeader(rows) {
        for (var i = 0; i < Math.min(rows.length, 40); i++) {
            var cells = filled(rows[i]);
            if (cells.length >= 3 && cells.some(function (c) { return /назв|наймен|name/i.test(c); })) return i;
        }
        for (i = 0; i < Math.min(rows.length, 40); i++) if (filled(rows[i]).length >= 4) return i;
        return -1;
    }

    function guessColumns(headers, saved) {
        var map = {};
        FIELDS.forEach(function (f) {
            map[f.key] = -1;

            if (saved && saved[f.key]) map[f.key] = headers.indexOf(saved[f.key]);
            if (map[f.key] === -1) {
                headers.some(function (h, i) {
                    if (h && f.re.test(h) && !(f.not && f.not.test(h))) { map[f.key] = i; return true; }
                    return false;
                });
            }
        });
        return map;
    }

    function guessSkuColumn(taken) {
        var used = Object.keys(taken).map(function (k) { return taken[k]; });
        var hits = state.headers.map(function () { return 0; });
        for (var i = state.headerIndex + 1; i < state.rows.length; i++) {
            var row = state.rows[i];
            for (var c = 0; c < hits.length; c++) if (row[c] && state.skus[row[c]] !== undefined) hits[c]++;
        }
        var best = -1;
        hits.forEach(function (n, c) { if (used.indexOf(c) === -1 && n > 0 && (best === -1 || n > hits[best])) best = c; });
        if (best === -1) for (var c2 = 0; c2 < hits.length; c2++) if (used.indexOf(c2) === -1) { best = c2; break; }
        return best;
    }

    function columns() {
        var map = {};
        FIELDS.forEach(function (f) { map[f.key] = +$('#importCol_' + f.key).value; });
        return map;
    }

    function buildSections() {
        var col = columns(), sections = [], current = null, bySku = {};
        var none = { path: '', title: '(без розділу)', items: [] };
        for (var i = state.headerIndex + 1; i < state.rows.length; i++) {
            var row = state.rows[i], cells = filled(row);
            if (!cells.length) continue;
            var sku = col.sku >= 0 ? (row[col.sku] || '') : '';
            var name = col.name >= 0 ? (row[col.name] || '') : '';
            if (cells.length === 1 && !(sku && name)) {
                var parts = cells[0].split('///').map(function (s) { return s.trim(); }).filter(Boolean);
                current = { path: cells[0], title: parts[parts.length - 1] || cells[0], parents: parts.slice(0, -1).join(' › '), items: [] };
                sections.push(current);
                continue;
            }
            if (!sku || !name) continue;
            if (bySku[sku]) continue;
            bySku[sku] = true;
            (current || none).items.push(row);
        }
        if (none.items.length) sections.unshift(none);
        return sections.filter(function (s) { return s.items.length; });
    }

    function categoryOptions(sec) {
        return '<option value="skip">— не додавати —</option>' +
            '<option value="new">➕ Створити категорію «' + esc(sec.title) + '»</option>' +
            A.flatTree(null, 0).map(function (x) {
                return '<option value="' + x.cat.id + '">' + '— '.repeat(x.depth) + esc(x.cat.name) + '</option>';
            }).join('');
    }

    function defaultTarget(sec, col) {
        var saved = (state.settings.sections || {})[sec.path];
        if (saved !== undefined) {
            var exists = saved === 'skip' || saved === 'new' || A.categories().some(function (c) { return c.id === +saved; });
            if (exists) return String(saved);
        }
        var votes = {}, best = 'skip', max = 0;
        sec.items.forEach(function (row) {
            var cat = state.skus[row[col.sku]];
            if (cat) { votes[cat] = (votes[cat] || 0) + 1; if (votes[cat] > max) { max = votes[cat]; best = String(cat); } }
        });
        return best;
    }

    function excludePrefixes() {
        return $('#importExclude').value.split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    }

    function renderSections() {
        var col = columns();
        state.sections = buildSections();
        var known = 0, fresh = 0;
        $('#importSections').innerHTML = state.sections.map(function (sec, i) {
            var existing = sec.items.filter(function (r) { return state.skus[r[col.sku]] !== undefined; }).length;
            known += existing; fresh += sec.items.length - existing;
            return '<tr>' +
                '<td><strong>' + esc(sec.title) + '</strong>' + (sec.parents ? '<span class="sku">' + esc(sec.parents) + '</span>' : '') + '</td>' +
                '<td class="num">' + sec.items.length + '</td>' +
                '<td class="num">' + existing + '</td>' +
                '<td class="num">' + (sec.items.length - existing) + '</td>' +
                '<td><select data-section="' + i + '" aria-label="Категорія для нових товарів">' + categoryOptions(sec) + '</select></td>' +
            '</tr>';
        }).join('') || '<tr><td colspan="5" class="muted">У файлі не знайдено рядків з артикулом і назвою. Перевірте відповідність колонок.</td></tr>';
        $$('#importSections select').forEach(function (sel) {
            sel.value = defaultTarget(state.sections[+sel.dataset.section], col);
            if (!sel.value) sel.value = 'skip';
        });
        $('#importSummary').textContent = 'У файлі «' + state.fileName + '»: товарів ' + (known + fresh) + ', з них уже є в каталозі ' + known + ', нових ' + fresh + '.';
        $('#importResult').hidden = true;
        $('#importApply').disabled = true;
    }

    function renderColumns() {
        var saved = state.settings.columns || {};
        var guess = guessColumns(state.headers, saved);
        $('#importColumns').innerHTML = FIELDS.map(function (f) {
            return '<label>' + f.label + '<select id="importCol_' + f.key + '">' +
                '<option value="-1">— немає —</option>' +
                state.headers.map(function (h, i) { return h ? '<option value="' + i + '">' + esc(h) + '</option>' : ''; }).join('') +
                '</select></label>';
        }).join('');
        if (guess.sku === -1) guess.sku = guessSkuColumn(guess);
        FIELDS.forEach(function (f) { $('#importCol_' + f.key).value = guess[f.key]; });
    }

    function number(v) {
        v = String(v == null ? '' : v).replace(/[\s ]/g, '').replace(',', '.');
        return v !== '' && isFinite(+v) ? +v : null;
    }

    function collect() {
        var col = columns(), exclude = excludePrefixes(), rows = [], sectionsMap = {}, skippedNew = 0, excluded = 0;
        if (col.sku < 0 || col.name < 0 || col.price < 0) return { error: 'Вкажіть колонки «Артикул», «Назва» і «Ціна».' };
        $$('#importSections select').forEach(function (sel) {
            var sec = state.sections[+sel.dataset.section], target = sel.value;
            sectionsMap[sec.path] = target;
            sec.items.forEach(function (r) {
                var sku = r[col.sku], name = r[col.name].replace(/\s+/g, ' ').trim();
                var isNew = state.skus[sku] === undefined;
                if (isNew) {
                    if (target === 'skip') { skippedNew++; return; }
                    var lower = name.toLowerCase();
                    if (exclude.some(function (p) { return lower.indexOf(p) === 0; })) { excluded++; return; }
                }
                var price = number(r[col.price]), sale = col.sale >= 0 ? number(r[col.sale]) : null;
                var row = { sku: sku, name: name, brand: col.brand >= 0 ? r[col.brand] : '', price: price, old_price: null };
                if (sale && price && sale < price) { row.price = sale; row.old_price = price; }
                if (col.stock >= 0) row.stock = number(r[col.stock]) || 0;
                if (isNew) {
                    if (target === 'new') row.category_new = sec.title;
                    else row.category_id = +target;
                }
                rows.push(row);
            });
        });
        var columnNames = {};
        FIELDS.forEach(function (f) { if (col[f.key] >= 0) columnNames[f.key] = state.headers[col[f.key]]; });
        return {
            rows: rows, skippedNew: skippedNew, excluded: excluded,
            options: {
                add_new: $('#importAddNew').checked, update_price: $('#importPrice').checked,
                update_availability: $('#importAvail').checked, update_name: $('#importName').checked,
                absent_missing: $('#importAbsent').checked, zero_stock: $('#importZero').value
            },
            settings: { columns: columnNames, sections: sectionsMap, exclude: $('#importExclude').value, zero_stock: $('#importZero').value }
        };
    }

    var TYPE = { 'new': ['ok', 'Новий товар'], price: ['pop', 'Ціна'], availability: ['warn', 'Наявність'], name: ['', 'Назва'], absent: ['off', 'Немає в прайсі'] };
    var AVAIL = { in_stock: 'В наявності', on_order: 'Під замовлення', absent: 'Відсутній' };

    function showValue(c, v) {
        if (v === null || v === undefined) return '—';
        if (c.type === 'price' || c.type === 'new') return A.money(v) + ' грн';
        if (c.type === 'availability' || c.type === 'absent') return AVAIL[v] || v;
        return esc(v);
    }

    function renderResult(res, data) {
        var s = res.stats, parts = [
            ['Нових товарів', s.created], ['Змінено цін', s.price], ['Змінено наявність', s.availability],
            ['Змінено назв', s.name], ['Позначено відсутніми', s.absent], ['Без змін', s.unchanged], ['Створено категорій', s.categories]
        ];
        var skipped = Object.keys(s.skipped || {}).map(function (k) { return k + ': ' + s.skipped[k]; });
        if (data.skippedNew + s.new_skipped) skipped.push('нові товари з розділів «не додавати»: ' + (data.skippedNew + s.new_skipped));
        if (data.excluded) skipped.push('аксесуари за списком винятків: ' + data.excluded);
        $('#importStats').innerHTML = parts.filter(function (p) { return p[1] || p[0] === 'Без змін'; }).map(function (p) {
            return '<div class="import_stat"><strong>' + p[1] + '</strong><span>' + p[0] + '</span></div>';
        }).join('');
        $('#importSkipped').textContent = skipped.length ? 'Пропущено — ' + skipped.join('; ') + '.' : '';
        var total = s.created + s.price + s.availability + s.name + s.absent;
        $('#importChanges').innerHTML = res.changes.map(function (c) {
            var t = TYPE[c.type] || ['', c.type];
            return '<tr><td><span class="badge ' + t[0] + '">' + t[1] + '</span></td><td>' + esc(c.name) + '<span class="sku">Арт. ' + esc(c.sku) + '</span></td>' +
                '<td class="num">' + showValue(c, c.old) + '</td><td class="num">' + showValue(c, c['new']) + '</td></tr>';
        }).join('') || '<tr><td colspan="4" class="muted">Змін немає: каталог уже відповідає прайсу.</td></tr>';
        $('#importMore').textContent = total > res.changes.length ? 'Показано перші ' + res.changes.length + ' змін із ' + total + '.' : '';
        $('#importResultTitle').textContent = res.dry ? 'Попередній перегляд — у каталозі ще нічого не змінено' : 'Готово — зміни внесено в каталог';
        $('#importResult').hidden = false;
        $('#importApply').disabled = !res.dry || !total;
    }

    function run(dry) {
        var data = collect();
        if (data.error) return A.toast(data.error, true);
        if (!data.rows.length) return A.toast('Немає рядків для імпорту.', true);
        if (!dry && !confirm('Внести зміни в каталог? Цю дію не можна скасувати однією кнопкою.')) return;
        var buttons = [$('#importCheck'), $('#importApply')];
        buttons.forEach(function (b) { b.disabled = true; });
        $('#importBusy').hidden = false;
        post('run', { dry: dry, options: data.options, rows: data.rows, settings: data.settings }).then(function (res) {
            $('#importBusy').hidden = true;
            $('#importCheck').disabled = false;
            if (!res.ok) return A.toast(res.message || 'Не вдалося виконати імпорт.', true);
            renderResult(res, data);
            if (!dry) {
                A.toast('Каталог оновлено');
                state.settings = data.settings;

                post('settings').then(function (r) { if (r.ok) state.skus = r.skus || {}; });
                document.dispatchEvent(new CustomEvent('admin:catalog-changed'));
            }
        });
    }

    $('#importFile').addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        $('#importSetup').hidden = true;
        $('#importBusy').hidden = false;
        Promise.all([window.OmegaSheet.read(file), post('settings')]).then(function (out) {
            $('#importBusy').hidden = true;
            var rows = out[0], res = out[1];
            if (!res.ok) return A.toast(res.message || 'Не вдалося завантажити налаштування.', true);
            state.rows = rows;
            state.fileName = file.name;
            state.settings = res.settings || {};
            state.skus = res.skus || {};
            state.headerIndex = findHeader(rows);
            if (state.headerIndex < 0) return A.toast('Не вдалося знайти рядок із назвами колонок.', true);
            state.headers = rows[state.headerIndex];
            $('#importExclude').value = state.settings.exclude !== undefined ? state.settings.exclude : DEFAULT_EXCLUDE;
            if (state.settings.zero_stock) $('#importZero').value = state.settings.zero_stock;
            renderColumns();
            renderSections();
            $('#importSetup').hidden = false;
        }).catch(function (e) {
            $('#importBusy').hidden = true;
            A.toast(e && e.message ? e.message : 'Не вдалося прочитати файл.', true);
        });
    });

    $('#importColumns').addEventListener('change', renderSections);
    $('#importSetup').addEventListener('change', function (e) {
        if (e.target.closest('#importColumns')) return;

        $('#importApply').disabled = true;
    });
    $('#importCheck').addEventListener('click', function () { run(true); });
    $('#importApply').addEventListener('click', function () { run(false); });
})();
