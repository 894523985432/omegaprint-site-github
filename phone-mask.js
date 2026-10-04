(function () {
    var TEMPLATE = '+380 (__) ___-__-__';
    var SLOTS = [];
    for (var i = 0; i < TEMPLATE.length; i++) if (TEMPLATE[i] === '_') SLOTS.push(i);

    function digitsOf(value) {
        var d = String(value || '').replace(/\D/g, '');
        if (d.indexOf('380') === 0 && d.length >= 12) d = d.slice(3);
        else if (d.indexOf('80') === 0 && d.length === 11) d = d.slice(2);

        d = d.replace(/^0+/, '');
        return d.slice(0, SLOTS.length);
    }

    function render(digits) {
        var out = TEMPLATE.split('');
        for (var i = 0; i < digits.length; i++) out[SLOTS[i]] = digits[i];
        return out.join('');
    }

    function caretAfter(input, count) {
        var pos = count ? SLOTS[count - 1] + 1 : SLOTS[0];
        if (document.activeElement === input) input.setSelectionRange(pos, pos);
    }

    function digitsBefore(pos) {
        var n = 0;
        for (var i = 0; i < SLOTS.length; i++) if (SLOTS[i] < pos) n++;
        return n;
    }

    function set(input, digits) {
        input.value = digits.length || document.activeElement === input ? render(digits) : '';
        input.dataset.digits = digits;
        caretAfter(input, digits.length);
    }

    function refresh(input) {
        var v = String(input.value || '');
        var d = digitsOf(v.indexOf('+380') === 0 ? v.slice(4) : v);
        input.value = d.length ? render(d) : '';
        input.dataset.digits = d;
    }

    function attach(input) {
        if (input.dataset.phoneMask) return;
        input.dataset.phoneMask = '1';
        input.setAttribute('inputmode', 'tel');
        input.removeAttribute('maxlength');
        input.placeholder = TEMPLATE;
        refresh(input);

        input.addEventListener('focus', function () {
            var d = input.dataset.digits || '';
            input.value = render(d);
            setTimeout(function () { caretAfter(input, d.length); }, 0);
        });
        input.addEventListener('blur', function () {
            if (!(input.dataset.digits || '').length) input.value = '';
        });
        input.addEventListener('keydown', function (e) {
            var d = input.dataset.digits || '';
            if (e.key === 'Backspace' || e.key === 'Delete') {
                e.preventDefault();
                var start = input.selectionStart, end = input.selectionEnd;
                var from = digitsBefore(start), to = digitsBefore(end);
                if (from === to) {
                    if (e.key === 'Backspace') from = Math.max(0, from - 1); else to = Math.min(d.length, to + 1);
                }
                set(input, d.slice(0, from) + d.slice(to));
                caretAfter(input, from);
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !/\d/.test(e.key)) {
                e.preventDefault();
            }
        });
        input.addEventListener('input', function () {

            var raw = input.value;
            set(input, digitsOf(raw.indexOf('+380 (') === 0 ? raw.slice(6) : raw));
        });
    }

    window.PhoneMask = {
        attach: attach, refresh: refresh,
        complete: function (input) { return (input.dataset.digits || digitsOf(input.value)).length === SLOTS.length; }
    };
})();
