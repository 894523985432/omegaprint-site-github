(function () {
    'use strict';

    var MAX_W = 1920, MAX_H = 1080, QUALITY = 0.8;

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () { resolve({ img: img, url: url }); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Не вдалося прочитати зображення.')); };
            img.src = url;
        });
    }

    function compressImage(file, options) {
        options = options || {};
        var maxW = options.maxWidth || MAX_W, maxH = options.maxHeight || MAX_H, quality = options.quality || QUALITY;
        var keep = { file: file, before: file.size, after: file.size, changed: false };
        if (!file || !/^image\/(jpeg|png|webp|bmp)$/i.test(file.type) || !window.HTMLCanvasElement) {
            return Promise.resolve(keep);
        }
        return loadImage(file).then(function (loaded) {
            var img = loaded.img;
            var w = img.naturalWidth, h = img.naturalHeight;
            var scale = Math.min(1, maxW / w, maxH / h);
            var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
            var canvas = document.createElement('canvas');
            canvas.width = cw;
            canvas.height = ch;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cw, ch);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, 0, 0, cw, ch);
            URL.revokeObjectURL(loaded.url);
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    if (!blob || (scale === 1 && blob.size >= file.size)) {
                        keep.width = w;
                        keep.height = h;
                        return resolve(keep);
                    }
                    var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    var out = new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
                    resolve({ file: out, before: file.size, after: out.size, width: cw, height: ch, changed: true });
                }, 'image/jpeg', quality);
            });
        }).catch(function () { return keep; });
    }

    function formatSize(bytes) {
        return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' МБ' : Math.max(1, Math.round(bytes / 1024)) + ' КБ';
    }

    function describe(result) {
        if (!result.changed) return '';
        return 'Фото стиснуто: ' + formatSize(result.before) + ' → ' + formatSize(result.after) + ' (' + result.width + '×' + result.height + ')';
    }

    window.OmegaImage = { compress: compressImage, describe: describe, formatSize: formatSize };
})();
