(function () {
    'use strict';

    function zipEntries(buf) {
        var dv = new DataView(buf), end = -1;
        for (var i = buf.byteLength - 22; i >= Math.max(0, buf.byteLength - 66000); i--) {
            if (dv.getUint32(i, true) === 0x06054b50) { end = i; break; }
        }
        if (end < 0) throw new Error('Це не файл Excel (.xlsx).');
        var count = dv.getUint16(end + 10, true), p = dv.getUint32(end + 16, true), entries = {};
        var utf8 = new TextDecoder('utf-8');
        for (var n = 0; n < count && dv.getUint32(p, true) === 0x02014b50; n++) {
            var nameLen = dv.getUint16(p + 28, true);
            var name = utf8.decode(new Uint8Array(buf, p + 46, nameLen));
            entries[name] = { method: dv.getUint16(p + 10, true), size: dv.getUint32(p + 20, true), offset: dv.getUint32(p + 42, true) };
            p += 46 + nameLen + dv.getUint16(p + 30, true) + dv.getUint16(p + 32, true);
        }
        return entries;
    }

    function zipText(buf, entry) {
        var dv = new DataView(buf);
        var start = entry.offset + 30 + dv.getUint16(entry.offset + 26, true) + dv.getUint16(entry.offset + 28, true);
        var data = new Uint8Array(buf, start, entry.size);
        if (entry.method === 0) return Promise.resolve(new TextDecoder('utf-8').decode(data));
        if (entry.method !== 8 || !window.DecompressionStream) {
            return Promise.reject(new Error('Браузер не може розпакувати цей файл. Оновіть браузер або збережіть прайс як CSV.'));
        }
        var stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        return new Response(stream).text();
    }

    function xml(text) {
        return new DOMParser().parseFromString(text, 'application/xml');
    }

    function columnIndex(ref) {
        var n = 0;
        for (var i = 0; i < ref.length; i++) {
            var c = ref.charCodeAt(i);
            if (c < 65 || c > 90) break;
            n = n * 26 + (c - 64);
        }
        return n - 1;
    }

    function cleanNumber(v) {
        if (!/^-?\d+\.\d{7,}$/.test(v) && !/e/i.test(v)) return v;
        var n = Number(v);
        return isFinite(n) ? String(Math.round(n * 10000) / 10000) : v;
    }

    function sheetRows(doc, shared) {
        var rows = [], list = doc.getElementsByTagName('row');
        for (var r = 0; r < list.length; r++) {
            var cells = list[r].getElementsByTagName('c'), row = [];
            for (var i = 0; i < cells.length; i++) {
                var c = cells[i], type = c.getAttribute('t'), value = '';
                if (type === 'inlineStr') {
                    var t = c.getElementsByTagName('t');
                    for (var k = 0; k < t.length; k++) value += t[k].textContent;
                } else {
                    var v = c.getElementsByTagName('v')[0];
                    value = v ? v.textContent : '';
                    if (type === 's') value = shared[+value] || '';
                    else if (!type || type === 'n') value = cleanNumber(value);
                }
                var col = columnIndex(c.getAttribute('r') || '');
                if (col < 0) col = row.length;
                while (row.length < col) row.push('');
                row[col] = value;
            }

            var index = (+list[r].getAttribute('r') || rows.length + 1) - 1;
            while (rows.length < index) rows.push([]);
            rows.push(row);
        }
        return rows;
    }

    function readXlsx(buf) {
        var entries = zipEntries(buf);
        var sheets = Object.keys(entries).filter(function (n) { return /^xl\/worksheets\/[^\/]+\.xml$/.test(n); });
        if (!sheets.length) throw new Error('У файлі немає аркушів.');
        var sharedEntry = entries['xl/sharedStrings.xml'];
        return (sharedEntry ? zipText(buf, sharedEntry) : Promise.resolve('')).then(function (text) {
            var shared = [];
            if (text) {
                var items = xml(text).getElementsByTagName('si');
                for (var i = 0; i < items.length; i++) {
                    var parts = items[i].getElementsByTagName('t'), s = '';
                    for (var k = 0; k < parts.length; k++) {
                        if (parts[k].parentNode.localName !== 'rPh') s += parts[k].textContent;
                    }
                    shared.push(s);
                }
            }
            return Promise.all(sheets.map(function (name) {
                return zipText(buf, entries[name]).then(function (t) { return sheetRows(xml(t), shared); });
            }));
        }).then(function (all) {

            return all.sort(function (a, b) { return b.length - a.length; })[0];
        });
    }

    function readCsv(buf) {
        var text = new TextDecoder('utf-8').decode(buf);

        if (text.indexOf('�') !== -1) text = new TextDecoder('windows-1251').decode(buf);
        text = text.replace(/^﻿/, '');
        var first = text.slice(0, text.indexOf('\n') === -1 ? text.length : text.indexOf('\n'));
        var delim = [';', '\t', ','].sort(function (a, b) { return first.split(b).length - first.split(a).length; })[0];
        var rows = [], row = [], cell = '', quoted = false;
        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            if (quoted) {
                if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (ch === '"') quoted = false;
                else cell += ch;
            } else if (ch === '"' && cell === '') quoted = true;
            else if (ch === delim) { row.push(cell); cell = ''; }
            else if (ch === '\n' || ch === '\r') {
                if (ch === '\r' && text[i + 1] === '\n') i++;
                row.push(cell); rows.push(row); row = []; cell = '';
            } else cell += ch;
        }
        if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
        return rows;
    }

    function read(file) {
        return file.arrayBuffer().then(function (buf) {
            var name = file.name.toLowerCase();
            if (/\.xls$/.test(name)) throw new Error('Старий формат .xls не підтримується. Відкрийте файл в Excel і збережіть як .xlsx.');
            var isZip = buf.byteLength > 4 && new DataView(buf).getUint32(0, true) === 0x04034b50;
            return isZip ? readXlsx(buf) : readCsv(buf);
        }).then(function (rows) {
            return rows.map(function (r) { return r.map(function (c) { return String(c == null ? '' : c).trim(); }); });
        });
    }

    window.OmegaSheet = { read: read };
})();
