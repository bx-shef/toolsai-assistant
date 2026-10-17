/**
 * Файлы в чате (1.8.0): до 5 файлов к вопросу. Текст достаётся здесь, в
 * браузере сотрудника, — сам файл на портал не загружается и не хранится.
 *
 * Что читаем (то, с чем работает продавец):
 *   PDF  — КП, договоры, счета, спецификации: штатный ui.pdfjs ядра (слой текста;
 *          скан без текста — «в файле нет текста»);
 *   DOCX — договоры и письма: word/document.xml, абзацы и таблицы;
 *   XLSX — прайсы, выгрузки, расчёты: листы построчно, ячейки через « | »;
 *   PPTX — презентации и КП: текст слайдов;
 *   CSV, TXT, MD, JSON — как есть (UTF-8, иначе windows-1251).
 * Картинки модель shef.toolsai не принимает (только текст) — понятный отказ.
 *
 * Zip (DOCX/XLSX/PPTX) читается сам: центральный каталог + DecompressionStream
 * ('deflate-raw'), без библиотек. Всё чистое, кроме чтения файла и pdf.js —
 * они подставляются (tests/files_test.mjs гоняет в node на настоящих zip).
 */
(function(root)
{
	'use strict';

	var MAX_FILES = 5;
	var MAX_BYTES = 15 * 1024 * 1024;
	var MAX_CHARS = 30000;
	var MAX_ROWS = 400;
	var PDF_PAGES = 60;

	var KINDS = {
		pdf: 'pdf', docx: 'docx', xlsx: 'xlsx', pptx: 'pptx',
		csv: 'csv', txt: 'txt', md: 'txt', json: 'txt', log: 'txt', tsv: 'csv'
	};
	var ACCEPT = '.pdf,.docx,.xlsx,.pptx,.csv,.tsv,.txt,.md,.json,.log';

	function FileError(message) { this.message = message; }

	function ext(name)
	{
		var m = /\.([a-z0-9]{1,5})$/i.exec(String(name || ''));
		return m ? m[1].toLowerCase() : '';
	}

	/** Вид файла по имени; null — не читаем (с причиной для сотрудника). */
	function kindOf(name)
	{
		var e = ext(name);
		if(KINDS[e]) { return {kind: KINDS[e]}; }
		if(/^(png|jpe?g|gif|webp|heic|bmp|tiff?)$/.test(e)) { return {error: 'картинки пока не читаю — нужен текст: PDF, Word, Excel'}; }
		if(/^(doc|xls|ppt|rtf|odt|ods)$/.test(e)) { return {error: 'старый формат — сохраните как ' + ({doc: 'DOCX', xls: 'XLSX', ppt: 'PPTX'}[e] || 'DOCX или PDF')}; }
		return {error: 'такой файл не читаю — нужен PDF, DOCX, XLSX, PPTX, CSV или TXT'};
	}

	/** Можно ли добавить ещё файлы: сколько уже есть, сколько хотят. */
	function room(current, adding)
	{
		return Math.max(0, Math.min(adding, MAX_FILES - current));
	}

	/** Текст в предел: обрезка по границе строки, пометка truncated. */
	function clip(text)
	{
		text = String(text || '').replace(/\u0000/g, '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
		if(text.length <= MAX_CHARS) { return {text: text, truncated: false}; }
		var cut = text.slice(0, MAX_CHARS);
		var nl = cut.lastIndexOf('\n');
		return {text: nl > MAX_CHARS * 0.8 ? cut.slice(0, nl) : cut, truncated: true};
	}

	/** Байты в текст: UTF-8; много «�» — значит windows-1251 (старые CSV из 1С и Excel). */
	function decodeText(bytes)
	{
		var utf = new TextDecoder('utf-8').decode(bytes);
		var bad = (utf.match(/�/g) || []).length;
		if(bad > 3 && bad > utf.length / 200)
		{
			try { return new TextDecoder('windows-1251').decode(bytes); } catch(e) {}
		}
		return utf.replace(/^﻿/, '');
	}

	function xmlText(s)
	{
		return String(s)
			.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'")
			.replace(/&#(\d+);/g, function(m, d) { return String.fromCodePoint(+d); })
			.replace(/&#x([0-9a-f]+);/gi, function(m, h) { return String.fromCodePoint(parseInt(h, 16)); })
			.replace(/&amp;/g, '&');
	}

	// --- zip: центральный каталог, stored (0) и deflate (8) ---

	function u16(b, i) { return b[i] | (b[i + 1] << 8); }
	function u32(b, i) { return (b[i] | (b[i + 1] << 8) | (b[i + 2] << 16) | (b[i + 3] << 24)) >>> 0; }

	/** Оглавление zip: имя → {method, offset, size}. Не zip — FileError. */
	function zipEntries(bytes)
	{
		var end = -1;
		for(var i = bytes.length - 22; i >= Math.max(0, bytes.length - 66000); i--)
		{
			if(u32(bytes, i) === 0x06054b50) { end = i; break; }
		}
		if(end < 0) { throw new FileError('файл повреждён или это не документ Office'); }
		var count = u16(bytes, end + 10);
		var pos = u32(bytes, end + 16);
		var out = {};
		var dec = new TextDecoder('utf-8');
		for(var n = 0; n < count && pos + 46 <= bytes.length; n++)
		{
			if(u32(bytes, pos) !== 0x02014b50) { break; }
			var nameLen = u16(bytes, pos + 28);
			var extra = u16(bytes, pos + 30);
			var comment = u16(bytes, pos + 32);
			var name = dec.decode(bytes.subarray(pos + 46, pos + 46 + nameLen));
			out[name] = {method: u16(bytes, pos + 10), size: u32(bytes, pos + 20), offset: u32(bytes, pos + 42)};
			pos += 46 + nameLen + extra + comment;
		}
		return out;
	}

	/** Содержимое файла из zip текстом; нет — null. */
	function zipRead(bytes, entries, name)
	{
		var e = entries[name];
		if(!e) { return Promise.resolve(null); }
		var at = e.offset;
		if(u32(bytes, at) !== 0x04034b50) { return Promise.reject(new FileError('файл повреждён')); }
		var start = at + 30 + u16(bytes, at + 26) + u16(bytes, at + 28);
		var data = bytes.subarray(start, start + e.size);
		if(e.method === 0) { return Promise.resolve(new TextDecoder('utf-8').decode(data)); }
		if(e.method !== 8 || typeof DecompressionStream === 'undefined') { return Promise.reject(new FileError('браузер не умеет распаковывать — обновите его')); }
		var stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
		return new Response(stream).text();
	}

	// --- форматы ---

	/** Текст фрагмента Word: прогоны w:t, табуляции, переносы; абзацы — через sep. */
	function wordText(xml, sep)
	{
		var out = [];
		xml.replace(/<w:p[\s>][\s\S]*?<\/w:p>|<w:p\/>/g, function(p)
		{
			var t = '';
			p.replace(/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>|<w:tab\/>|<w:br[^>]*\/>/g, function(m, v)
			{
				t += v !== undefined ? xmlText(v) : (m.indexOf('tab') > 0 ? '\t' : '\n');
				return m;
			});
			out.push(t);
			return p;
		});
		return out.join(sep);
	}

	function docx(bytes)
	{
		var entries = zipEntries(bytes);
		return zipRead(bytes, entries, 'word/document.xml').then(function(xml)
		{
			if(xml === null) { throw new FileError('это не документ Word'); }
			// Таблица: строка — ячейки через « | », абзацы ячейки — через пробел; потом обычные абзацы
			var body = xml.replace(/<w:tbl>[\s\S]*?<\/w:tbl>/g, function(tbl)
			{
				var rows = [];
				tbl.replace(/<w:tr[\s>][\s\S]*?<\/w:tr>/g, function(tr)
				{
					var cells = [];
					tr.replace(/<w:tc[\s>][\s\S]*?<\/w:tc>/g, function(tc) { cells.push(wordText(tc, ' ').replace(/\s+/g, ' ').trim()); return tc; });
					rows.push(cells.join(' | '));
					return tr;
				});
				return '<w:p><w:t>' + rows.join('\u0001').replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</w:t></w:p>';
			});
			return wordText(body, '\n').replace(/\u0001/g, '\n');
		});
	}

	function colIndex(ref)
	{
		var letters = /^([A-Z]+)/.exec(ref || '');
		if(!letters) { return -1; }
		var n = 0;
		for(var i = 0; i < letters[1].length; i++) { n = n * 26 + letters[1].charCodeAt(i) - 64; }
		return n - 1;
	}

	function xlsx(bytes)
	{
		var entries = zipEntries(bytes);
		return Promise.all([zipRead(bytes, entries, 'xl/sharedStrings.xml'), zipRead(bytes, entries, 'xl/workbook.xml'), zipRead(bytes, entries, 'xl/_rels/workbook.xml.rels')]).then(function(r)
		{
			if(r[1] === null) { throw new FileError('это не книга Excel'); }
			var shared = [];
			(r[0] || '').replace(/<si>([\s\S]*?)<\/si>/g, function(m, si)
			{
				var t = '';
				si.replace(/<t(?:\s[^>]*)?>([^<]*)<\/t>/g, function(x, v) { t += xmlText(v); return x; });
				shared.push(t);
				return m;
			});
			var rels = {};
			(r[2] || '').replace(/<Relationship\s[^>]*>/g, function(m)
			{
				var id = /Id="([^"]+)"/.exec(m), target = /Target="([^"]+)"/.exec(m);
				if(id && target) { rels[id[1]] = target[1].replace(/^\/?(xl\/)?/, 'xl/'); }
				return m;
			});
			var sheets = [];
			r[1].replace(/<sheet\s[^>]*>/g, function(m)
			{
				var name = /name="([^"]*)"/.exec(m), rid = /r:id="([^"]+)"/.exec(m);
				sheets.push({name: name ? xmlText(name[1]) : 'Лист', path: rid && rels[rid[1]] ? rels[rid[1]] : 'xl/worksheets/sheet' + (sheets.length + 1) + '.xml'});
				return m;
			});
			return Promise.all(sheets.map(function(s) { return zipRead(bytes, entries, s.path); })).then(function(xmls)
			{
				var out = [];
				var rowsLeft = MAX_ROWS;
				sheets.forEach(function(sheet, si)
				{
					if(!xmls[si] || rowsLeft <= 0) { return; }
					out.push('## Лист «' + sheet.name + '»');
					xmls[si].replace(/<row[^>]*>([\s\S]*?)<\/row>/g, function(m, row)
					{
						if(rowsLeft <= 0) { return m; }
						var cells = [];
						row.replace(/<c\s([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g, function(x, attrs, inner)
						{
							var ref = /r="([A-Z]+)\d+"/.exec(attrs), type = /t="([^"]+)"/.exec(attrs);
							var v = inner ? /<v>([^<]*)<\/v>/.exec(inner) : null;
							var value = '';
							if(type && type[1] === 's' && v) { value = shared[+v[1]] || ''; }
							else if(type && type[1] === 'inlineStr' && inner) { inner.replace(/<t(?:\s[^>]*)?>([^<]*)<\/t>/g, function(y, t) { value += xmlText(t); return y; }); }
							else if(v) { value = xmlText(v[1]); }
							var idx = ref ? colIndex(ref[1]) : cells.length;
							while(cells.length < idx) { cells.push(''); }
							cells[idx] = value.replace(/\s+/g, ' ').trim();
							return x;
						});
						while(cells.length && cells[cells.length - 1] === '') { cells.pop(); }
						if(cells.length) { out.push(cells.join(' | ')); rowsLeft--; }
						return m;
					});
				});
				if(rowsLeft <= 0) { out.push('… дальше строки не показаны (больше ' + MAX_ROWS + ')'); }
				return out.join('\n');
			});
		});
	}

	function pptx(bytes)
	{
		var entries = zipEntries(bytes);
		var slides = Object.keys(entries).filter(function(n) { return /^ppt\/slides\/slide\d+\.xml$/.test(n); })
			.sort(function(a, b) { return +/(\d+)\.xml$/.exec(a)[1] - +/(\d+)\.xml$/.exec(b)[1]; });
		if(!slides.length) { return Promise.reject(new FileError('это не презентация PowerPoint')); }
		return Promise.all(slides.map(function(s) { return zipRead(bytes, entries, s); })).then(function(xmls)
		{
			return xmls.map(function(xml, i)
			{
				var lines = [];
				String(xml || '').replace(/<a:p>([\s\S]*?)<\/a:p>/g, function(m, p)
				{
					var t = '';
					p.replace(/<a:t>([^<]*)<\/a:t>/g, function(x, v) { t += xmlText(v); return x; });
					if(t.trim()) { lines.push(t.trim()); }
					return m;
				});
				return '## Слайд ' + (i + 1) + '\n' + lines.join('\n');
			}).join('\n\n');
		});
	}

	/**
	 * Текст страницы PDF по координатам: строки сверху вниз, куски строки слева
	 * направо, пробел — где между кусками есть зазор. Порядок кусков в потоке PDF
	 * не обязан совпадать с порядком чтения (LibreOffice пишет знаки отдельно).
	 * Нет координат — по порядку потока.
	 */
	function pdfPageText(content)
	{
		var items = (content.items || []).filter(function(it) { return typeof it.str === 'string'; });
		if(!items.every(function(it) { return it.transform && it.transform.length >= 6; }))
		{
			return items.map(function(it) { return it.str + (it.hasEOL ? '\n' : ' '); }).join('').trim();
		}
		var lines = [];
		items.forEach(function(it)
		{
			if(!it.str.trim()) { return; }
			var y = it.transform[5], h = Math.abs(it.height || it.transform[3] || 10);
			var line = lines.filter(function(l) { return Math.abs(l.y - y) <= h * 0.5; })[0];
			if(!line) { line = {y: y, items: []}; lines.push(line); }
			line.items.push(it);
		});
		lines.sort(function(a, b) { return b.y - a.y; });
		return lines.map(function(line)
		{
			line.items.sort(function(a, b) { return a.transform[4] - b.transform[4]; });
			var out = '', end = null;
			line.items.forEach(function(it)
			{
				var x = it.transform[4];
				if(end !== null && x - end > Math.abs(it.height || 10) * 0.15 && !/\s$/.test(out)) { out += ' '; }
				out += it.str;
				end = x + (it.width || 0);
			});
			return out.replace(/\s+/g, ' ').trim();
		}).join('\n');
	}

	/** PDF через ui.pdfjs ядра (подставляется: loadPdf(bytes) → документ pdf.js). */
	function pdf(bytes, loadPdf)
	{
		if(!loadPdf) { return Promise.reject(new FileError('чтение PDF недоступно на этой странице')); }
		return loadPdf(bytes).then(function(doc)
		{
			var pages = Math.min(doc.numPages, PDF_PAGES);
			var jobs = [];
			for(var i = 1; i <= pages; i++)
			{
				jobs.push(doc.getPage(i).then(function(page) { return page.getTextContent(); }).then(pdfPageText));
			}
			return Promise.all(jobs).then(function(texts)
			{
				var text = texts.join('\n\n');
				if(!text.replace(/\s/g, '')) { throw new FileError('в PDF нет текста — похоже на скан'); }
				return doc.numPages > pages ? text + '\n… дальше страницы не показаны (больше ' + PDF_PAGES + ')' : text;
			});
		});
	}

	/**
	 * Прочитать файл: {name, size, bytes()} → {name, kind, text, truncated} или
	 * отказ с понятной причиной (FileError.message).
	 * deps: {loadPdf(Uint8Array): Promise<pdf.js document>}
	 */
	function extract(file, deps)
	{
		var kind = kindOf(file.name);
		if(kind.error) { return Promise.reject(new FileError(kind.error)); }
		if(file.size > MAX_BYTES) { return Promise.reject(new FileError('больше 15 МБ')); }
		return Promise.resolve(file.bytes()).then(function(bytes)
		{
			bytes = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
			switch(kind.kind)
			{
				case 'pdf': return pdf(bytes, deps && deps.loadPdf);
				case 'docx': return docx(bytes);
				case 'xlsx': return xlsx(bytes);
				case 'pptx': return pptx(bytes);
				default: return decodeText(bytes);
			}
		}).then(function(text)
		{
			var c = clip(text);
			if(!c.text) { throw new FileError('в файле нет текста'); }
			return {name: String(file.name).slice(0, 120), kind: kind.kind, text: c.text, truncated: c.truncated};
		}, function(e)
		{
			throw e instanceof FileError ? e : new FileError('не удалось прочитать файл');
		});
	}

	var Files = {
		MAX_FILES: MAX_FILES,
		MAX_CHARS: MAX_CHARS,
		ACCEPT: ACCEPT,
		FileError: FileError,
		kindOf: kindOf,
		pdfPageText: pdfPageText,
		room: room,
		clip: clip,
		decodeText: decodeText,
		extract: extract
	};

	if(typeof module === 'object' && module.exports)
	{
		module.exports = Files;
	}
	else if(root.BX && root.BX.namespace)
	{
		root.BX.namespace('BX.Shef.ToolsAiAssistant').Files = Files;
	}
})(typeof window !== 'undefined' ? window : this);
