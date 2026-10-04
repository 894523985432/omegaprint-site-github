(function ($) {
    if (!$) return;
    if (!$.fn.slider) $.fn.slider = function () { return this; };
    if (!$.fn.inputmask) $.fn.inputmask = function () {
        return this.each(function () {
            var input = this;
            if (input.tagName !== 'INPUT' || !window.PhoneMask || input.inputmask) return;
            window.PhoneMask.attach(input);
            input.inputmask = {
                isComplete: function () { return window.PhoneMask.complete(input); },
                unmaskedvalue: function () { return input.dataset.digits || ''; }
            };
        });
    };
})(window.jQuery);

jQuery(function ($) {

    $('.slick-initialized').each(function () {
        var $slider = $(this);

        $slider.css({ height: $slider.outerHeight(), overflow: 'hidden' });
        var release = function () { $slider.css({ height: '', overflow: '' }); };
        $slider.one('init', release);
        setTimeout(release, 3000);
        var $originals = $slider.find('> .slick-list > .slick-track > .slick-slide')
            .not('.slick-cloned')
            .map(function () {
                var $slide = $(this);
                var $inner = $slide.children('div').length === 1 ? $slide.children('div').children() : $slide.children();
                return $inner.removeAttr('tabindex').css({ width: '', display: '' }).get();
            });
        $slider.empty().append($originals)
            .removeClass('slick-initialized slick-slider slick-dotted');
    });

    $('.folowe_link').on('click', function () {
        $('#folowe_block').submit();
    });
    $('#sign_up.modal, #sign_in.modal, #call_back.modal, #send_service.modal').on('shown.bs.modal', function () {
        $(this).find('input[type=text].form-control').first().trigger('focus');
    });
});

(function () {
    var opened = Date.now(), human = false;
    function forms() { return document.querySelectorAll('form[action*="send-to-telegram"]'); }
    function field(form, name) {
        var input = form.querySelector('input[name="' + name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = name === 'contact_url' ? 'text' : 'hidden';
            input.name = name;
            if (name === 'contact_url') {
                input.tabIndex = -1;
                input.autocomplete = 'off';
                input.setAttribute('aria-hidden', 'true');
                input.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0';
            }
            form.appendChild(input);
        }
        return input;
    }
    function prepare() {
        Array.prototype.forEach.call(forms(), function (form) {
            field(form, 'contact_url');
            field(form, '_human').value = human ? '1' : '';
            field(form, '_elapsed').value = String(Date.now() - opened);
        });
    }
    function markHuman() {
        if (human) return;
        human = true;
        prepare();
    }
    ['keydown', 'pointerdown', 'touchstart', 'mousedown'].forEach(function (e) {
        document.addEventListener(e, markHuman, { capture: true, passive: true });
    });

    document.addEventListener('submit', function (e) {
        if (e.target && e.target.matches && e.target.matches('form[action*="send-to-telegram"]')) prepare();
    }, true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prepare); else prepare();
})();
