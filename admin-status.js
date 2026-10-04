(function () {
    var A = window.AdminCore;
    var menu = null, openFor = null;

    function close() {
        if (!menu) return;
        menu.remove();
        menu = null;
        if (openFor) openFor._statusBtn.setAttribute('aria-expanded', 'false');
        openFor = null;
    }

    function pill(option) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'option');
        b.textContent = option.textContent;
        b.dataset.value = option.value;
        if (option.style.background) {
            b.className = 'status_pill';
            b.style.background = option.style.background;
            b.style.color = option.style.color;
        } else {
            b.className = 'status_plain_item';
        }
        if (option.selected) b.setAttribute('aria-selected', 'true');
        return b;
    }

    function open(select) {
        close();
        var btn = select._statusBtn;
        menu = document.createElement('div');
        menu.className = 'status_menu';
        menu.setAttribute('role', 'listbox');
        Array.prototype.forEach.call(select.children, function (node) {
            if (node.tagName === 'OPTGROUP') {
                var title = document.createElement('div');
                title.className = 'status_group';
                title.textContent = node.label;
                menu.appendChild(title);
                Array.prototype.forEach.call(node.children, function (o) { menu.appendChild(pill(o)); });
            } else {
                menu.appendChild(pill(node));
            }
        });
        document.body.appendChild(menu);
        openFor = select;
        btn.setAttribute('aria-expanded', 'true');

        var r = btn.getBoundingClientRect();
        var top = r.bottom + 6;
        if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - 6 - menu.offsetHeight);
        menu.style.top = top + 'px';
        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';

        var current = menu.querySelector('[aria-selected]') || menu.querySelector('button');
        if (current) current.focus();

        menu.addEventListener('click', function (e) {
            var item = e.target.closest('button');
            if (!item) return;
            var changed = select.value !== item.dataset.value;
            select.value = item.dataset.value;
            close();
            btn.focus();
            if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
            sync(select);
        });
        menu.addEventListener('keydown', function (e) {
            var items = Array.prototype.slice.call(menu.querySelectorAll('button'));
            var i = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
            } else if (e.key === 'Escape' || e.key === 'Tab') {
                e.preventDefault();
                close();
                btn.focus();
            }
        });
    }

    function sync(select) {
        var btn = select._statusBtn;
        var o = select.options[select.selectedIndex];
        btn.firstChild.textContent = o ? o.textContent : '—';
        btn.style.background = o && o.style.background ? o.style.background : '';
        btn.style.color = o && o.style.background ? o.style.color : '';
        btn.disabled = select.disabled;
    }

    A.statusMenu = function (select) {
        if (!select._statusBtn) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'status_btn';
            btn.setAttribute('aria-haspopup', 'listbox');
            btn.setAttribute('aria-expanded', 'false');
            if (select.getAttribute('aria-label')) btn.setAttribute('aria-label', select.getAttribute('aria-label'));
            btn.appendChild(document.createElement('span'));
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (openFor === select) close(); else open(select);
            });
            select._statusBtn = btn;
            select.classList.add('status_native');
            select.insertAdjacentElement('afterend', btn);
        }
        sync(select);
    };

    document.addEventListener('click', function (e) {
        if (menu && !menu.contains(e.target)) close();
    });
    window.addEventListener('resize', close);
    document.addEventListener('scroll', function (e) { if (menu && !menu.contains(e.target)) close(); }, true);
})();
