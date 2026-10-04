(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    var state = { categories: [], page: 1, q: '', cat: '' };
    var PER_PAGE = 50;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var AVAILABILITY = {
        in_stock: ['ok', 'В наявності'],
        on_order: ['warn', 'Під замовлення'],
        absent: ['off', 'Відсутній']
    };

    function availabilityBadge(value) {
        var a = AVAILABILITY[value] || AVAILABILITY.in_stock;
        return '<span class="badge ' + a[0] + '">' + a[1] + '</span>';
    }

    function money(n) {
        return Number(n).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function toast(text, isError) {
        var el = $('#toast');
        el.textContent = text;
        el.classList.toggle('err', !!isError);
        el.classList.add('show');
        clearTimeout(toast.timer);
        toast.timer = setTimeout(function () { el.classList.remove('show'); }, 2600);
    }

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
    }

    function adminPost(action, body) {
        return apiPost('api/admin.php?action=' + action, body);
    }

    function apiPost(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Помилка сервера (' + r.status + ').' }; })
                .then(function (data) {
                    if (r.status === 401 || r.status === 403) showLogin(data.message);
                    return data;
                });
        }).catch(function () {
            return { ok: false, message: 'Немає з\'єднання із сервером.' };
        });
    }

    function formData(obj) {
        var fd = new FormData();
        Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
        return fd;
    }

    function showLogin(message) {
        $('#appView').hidden = true;
        $('#userBox').hidden = true;
        $('#loginView').hidden = false;
        $('#loginError').textContent = message || '';
    }

    function showApp(user) {
        $('#loginView').hidden = true;
        $('#appView').hidden = false;
        $('#userBox').hidden = false;
        $('#userName').textContent = user.username + ' (' + user.email + ')';
        loadCategories().then(loadProducts);
        document.dispatchEvent(new CustomEvent('admin:ready', { detail: user }));
    }

    function checkUser() {
        getJSON('auth.php?action=me').then(function (res) {
            if (res.user && res.user.is_admin) showApp(res.user);
            else showLogin(res.user ? 'Обліковий запис ' + res.user.email + ' не має прав адміністратора.' : '');
        }).catch(function () { showLogin('Не вдалося зв\'язатися із сервером.'); });
    }

    $('#loginForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = this.querySelector('button[type=submit]');
        btn.disabled = true;
        fetch('auth.php?action=login', { method: 'POST', credentials: 'same-origin', body: new FormData(this) })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) {
                    var errors = res.errors || {};
                    $('#loginError').textContent = errors.password_hash || res.message || 'Не вдалося увійти.';
                } else if (!res.user.is_admin) {
                    $('#loginError').textContent = 'Цей обліковий запис не має прав адміністратора.';
                } else {
                    showApp(res.user);
                }
            })
            .catch(function () { $('#loginError').textContent = 'Не вдалося зв\'язатися із сервером.'; })
            .then(function () { btn.disabled = false; });
    });

    $('#logoutBtn').addEventListener('click', function () {
        fetch('auth.php?action=logout', { method: 'POST', credentials: 'same-origin' }).then(function () { showLogin(); });
    });

    function showTab(name) {
        $$('.tab').forEach(function (t) {
            var active = t.dataset.tab === name;
            t.classList.toggle('active', active);
            t.setAttribute('aria-selected', active);
            $('#tab-' + t.dataset.tab).hidden = !active;
        });
        document.dispatchEvent(new CustomEvent('admin:tab', { detail: name }));
    }
    $$('.tab').forEach(function (tab) {
        tab.addEventListener('click', function () { showTab(tab.dataset.tab); });
    });

    function loadCategories() {
        return getJSON('api/shop.php?action=categories').then(function (res) {
            state.categories = res.categories || [];
            renderCategoryTree();
            fillCategorySelects();
        });
    }

    function childrenOf(parentId) {
        return state.categories.filter(function (c) { return c.parent_id === parentId; });
    }

    function flatTree(parentId, depth, out) {
        out = out || [];
        childrenOf(parentId).forEach(function (c) {
            out.push({ cat: c, depth: depth });
            flatTree(c.id, depth + 1, out);
        });
        return out;
    }

    function categoryName(id) {
        var c = state.categories.filter(function (x) { return x.id === id; })[0];
        return c ? c.name : '—';
    }

    function fillCategorySelects() {
        var options = flatTree(null, 0).map(function (x) {
            return '<option value="' + x.cat.id + '">' + '— '.repeat(x.depth) + esc(x.cat.name) + '</option>';
        }).join('');
        $('#productCat').innerHTML = '<option value="">Усі категорії</option>' + options;
        $('#productCat').value = state.cat;
        $('#productForm [name=category_id]').innerHTML = '<option value="">Без категорії</option>' + options;
        $('#categoryForm [name=parent_id]').innerHTML = '<option value="">— Верхній рівень —</option>' + options;
    }

    function renderCategoryTree() {
        function branch(parentId) {
            return childrenOf(parentId).map(function (c) {
                var kids = childrenOf(c.id);
                return '<li>' +
                    '<div class="cat_row">' +
                        '<span class="name">' + esc(c.name) + '</span>' +
                        '<span class="count">' + c.count + ' тов.</span>' +
                        '<button type="button" class="btn small" data-cat-add="' + c.id + '">+ Підкатегорія</button>' +
                        '<button type="button" class="btn small" data-cat-edit="' + c.id + '">Змінити</button>' +
                        '<button type="button" class="btn small danger" data-cat-del="' + c.id + '">Видалити</button>' +
                    '</div>' +
                    (kids.length ? '<ul>' + branch(c.id) + '</ul>' : '') +
                '</li>';
            }).join('');
        }
        $('#categoryTree').innerHTML = branch(null) || '<li class="muted">Категорій ще немає.</li>';
    }

    function openCategoryDialog(cat, parentId) {
        var form = $('#categoryForm');
        form.reset();
        clearErrors(form);
        $('#categoryDialogTitle').textContent = cat ? 'Редагувати категорію' : 'Нова категорія';
        form.elements.id.value = cat ? cat.id : '';
        form.elements.name.value = cat ? cat.name : '';
        form.elements.sort.value = cat ? cat.sort : 0;
        form.elements.parent_id.value = cat ? (cat.parent_id || '') : (parentId || '');

        var blocked = cat ? [cat.id].concat(flatTree(cat.id, 0).map(function (x) { return x.cat.id; })) : [];
        $$('option', form.elements.parent_id).forEach(function (o) { o.disabled = blocked.indexOf(+o.value) !== -1; });
        $('#categoryDialog').showModal();
        form.elements.name.focus();
    }

    $('#addCategoryBtn').addEventListener('click', function () { openCategoryDialog(null); });
    $('#categoryTree').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var find = function (id) { return state.categories.filter(function (c) { return c.id === +id; })[0]; };
        if (btn.dataset.catAdd) openCategoryDialog(null, btn.dataset.catAdd);
        if (btn.dataset.catEdit) openCategoryDialog(find(btn.dataset.catEdit));
        if (btn.dataset.catDel) {
            var cat = find(btn.dataset.catDel);
            var note = cat.count ? '\nТовари цієї категорії (' + cat.count + ') залишаться без категорії.' : '';
            if (!confirm('Видалити категорію «' + cat.name + '»?' + note)) return;
            adminPost('category_delete', formData({ id: cat.id })).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
                toast('Категорію видалено');
                loadCategories().then(loadProducts);
            });
        }
    });

    $('#categoryForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        clearErrors(form);
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        adminPost('category_save', new FormData(form)).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return showErrors(form, res, '#categoryError');
            $('#categoryDialog').close();
            toast('Категорію збережено');
            loadCategories();
        });
    });

    function loadProducts() {
        var params = new URLSearchParams({ action: 'products', sort: 'new', per: PER_PAGE, page: state.page });
        if (state.q) params.set('q', state.q);
        if (state.cat) params.set('cat', state.cat);
        return getJSON('api/shop.php?' + params).then(function (res) {
            state.products = res.items || [];
            $('#productInfo').textContent = 'Товарів: ' + res.total;
            $('#productRows').innerHTML = state.products.map(function (p) {
                return '<tr>' +
                    '<td><img src="' + esc(p.image || 'icons/no-image.svg') + '" alt=""></td>' +
                    '<td><a href="product.html?id=' + p.id + '" target="_blank" rel="noopener">' + esc(p.name) + '</a>' +
                        (p.sku ? '<span class="sku">Арт. ' + esc(p.sku) + '</span>' : '') + '</td>' +
                    '<td>' + esc(p.category_id ? categoryName(p.category_id) : '—') + '</td>' +
                    '<td class="num">' + (p.old_price ? '<span class="old">' + money(p.old_price) + '</span>' : '') + money(p.price) + '</td>' +
                    '<td>' + availabilityBadge(p.availability) +
                        (p.popular ? ' <span class="badge pop">Популярний</span>' : '') + '</td>' +
                    '<td class="actions">' +
                        '<button type="button" class="btn small" data-edit="' + p.id + '">Змінити</button> ' +
                        '<button type="button" class="btn small danger" data-del="' + p.id + '">Видалити</button>' +
                    '</td>' +
                '</tr>';
            }).join('') || '<tr><td colspan="6" class="muted">Нічого не знайдено.</td></tr>';

            var pager = '';
            if (res.pages > 1) {

                for (var i = 1; i <= res.pages; i++) {
                    if (i === 1 || i === res.pages || Math.abs(i - res.page) <= 2) {
                        pager += '<button type="button" data-page="' + i + '"' + (i === res.page ? ' aria-current="page"' : '') + '>' + i + '</button>';
                    } else if (Math.abs(i - res.page) === 3) {
                        pager += '<span class="pager_gap">…</span>';
                    }
                }
            }
            $('#productPager').innerHTML = pager;
        });
    }

    var searchTimer;
    $('#productSearch').addEventListener('input', function () {
        clearTimeout(searchTimer);
        var value = this.value.trim();
        searchTimer = setTimeout(function () { state.q = value; state.page = 1; loadProducts(); }, 300);
    });
    $('#productCat').addEventListener('change', function () { state.cat = this.value; state.page = 1; loadProducts(); });
    $('#productPager').addEventListener('click', function (e) {
        if (e.target.dataset.page) { state.page = +e.target.dataset.page; loadProducts(); }
    });

    function openProductDialog(p) {
        var form = $('#productForm');
        form.reset();
        clearErrors(form);
        $('#productDialogTitle').textContent = p ? 'Редагувати товар' : 'Новий товар';
        form.elements.id.value = p ? p.id : '';
        form.elements.name.value = p ? p.name : '';
        form.elements.sku.value = p ? p.sku : '';
        form.elements.category_id.value = p && p.category_id ? p.category_id : (state.cat || '');
        form.elements.price.value = p ? p.price : '';
        form.elements.old_price.value = p && p.old_price ? p.old_price : '';
        form.elements.availability.value = p ? p.availability : 'in_stock';
        form.elements.popular.checked = p ? p.popular : false;
        form.elements.description.value = p ? p.description : '';
        $('#photoPreview').src = p && p.image ? p.image : 'icons/no-image.svg';
        $('#removeImageRow').hidden = !(p && p.image);

        document.dispatchEvent(new CustomEvent('admin:product-form', { detail: p }));
        $('#productDialog').showModal();
        form.elements.name.focus();
    }

    $('#productForm [name=image]').addEventListener('change', function () {
        var file = this.files[0];
        if (file) $('#photoPreview').src = URL.createObjectURL(file);
    });

    $('#addProductBtn').addEventListener('click', function () { openProductDialog(null); });
    $('#productRows').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var p = (state.products || []).filter(function (x) { return x.id === +(btn.dataset.edit || btn.dataset.del); })[0];
        if (!p) return;
        if (btn.dataset.edit) openProductDialog(p);
        if (btn.dataset.del) {
            if (!confirm('Видалити товар «' + p.name + '»?')) return;
            adminPost('product_delete', formData({ id: p.id })).then(function (res) {
                if (!res.ok) return toast(res.message || 'Не вдалося видалити.', true);
                toast('Товар видалено');
                loadCategories().then(loadProducts);
            });
        }
    });

    $('#productForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = this;
        clearErrors(form);
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        var fd = new FormData(form);
        var note = '';
        prepareImage(fd, form.elements.image).then(function (info) {
            note = info;
            return adminPost('product_save', fd);
        }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) return showErrors(form, res, '#productError');
            $('#productDialog').close();
            toast('Товар збережено' + (note ? '. ' + note : ''));
            loadCategories().then(loadProducts);
        });
    });

    function clearErrors(form) {
        $$('.field_error', form).forEach(function (el) { el.textContent = ''; });
        $$('.error', form).forEach(function (el) { el.textContent = ''; });
    }

    function showErrors(form, res, generalSel) {
        var errors = res.errors || {};
        Object.keys(errors).forEach(function (field) {
            var el = form.querySelector('.field_error[data-for="' + field + '"]');
            if (el) el.textContent = errors[field];
        });
        if (res.message) $(generalSel).textContent = res.message;
        var first = form.querySelector('.field_error:not(:empty)');
        if (first) {
            var input = first.parentNode.querySelector('input, select, textarea');
            if (input) input.focus();
        }
    }

    $$('dialog [data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('dialog').close(); });
    });

    function prepareImage(fd, input, field) {
        field = field || 'image';
        if (!input || !input.files.length) { fd.delete(field); return Promise.resolve(''); }
        if (!window.OmegaImage) return Promise.resolve('');
        return window.OmegaImage.compress(input.files[0]).then(function (res) {
            if (res.changed) fd.set(field, res.file, res.file.name);
            return window.OmegaImage.describe(res);
        });
    }

    document.addEventListener('admin:catalog-changed', function () { loadCategories().then(loadProducts); });

    window.AdminCore = {
        $: $, $$: $$, esc: esc, money: money, toast: toast, getJSON: getJSON, apiPost: apiPost,
        formData: formData, clearErrors: clearErrors, showErrors: showErrors, showTab: showTab, prepareImage: prepareImage,
        adminPost: adminPost, categories: function () { return state.categories; }, flatTree: flatTree, categoryName: categoryName
    };

    checkUser();
})();
