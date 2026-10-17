<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Файлы сотрудника в чате (1.8.0): до 5 файлов к вопросу — КП, договор, счёт,
 * прайс, выгрузка, презентация, переписка. Текст из файла достаёт окно в
 * браузере (files.js: PDF — штатный ui.pdfjs, DOCX/XLSX/PPTX — из zip, CSV и
 * TXT как есть); сам файл на портал не загружается и не хранится. Сюда
 * приходит только текст — он ничем не опаснее того, что сотрудник вставил бы в
 * вопрос руками, но объём ограничиваем: модель и квота не резиновые.
 *
 * Разбор — чистый (chatfiles_test.php): имя без путей и управляющих символов,
 * вид из списка KINDS, текст до FILE_MAX знаков; всего до TOTAL_MAX — свежие
 * файлы важнее старых (последний вопрос обычно о последнем файле).
 */
final class ChatFiles
{
	/** Файлов к одному вопросу. */
	public const PER_MESSAGE = 5;
	/** Знаков текста из одного файла. */
	public const FILE_MAX = 30000;
	/** Знаков из всех файлов переписки вместе. */
	public const TOTAL_MAX = 60000;

	public const KINDS = ['pdf', 'docx', 'xlsx', 'pptx', 'csv', 'txt'];

	/**
	 * Файлы одного сообщения из запроса окна; мусор отбрасывается.
	 *
	 * @return list<array{name: string, kind: string, text: string, truncated: bool}>
	 */
	public static function fromMessage(mixed $raw): array
	{
		$out = [];
		foreach(is_array($raw) ? $raw : [] as $file)
		{
			if(!is_array($file) || !is_string($file['text'] ?? null))
			{
				continue;
			}
			$text = trim(str_replace("\0", '', $file['text']));
			if($text === '')
			{
				continue;
			}
			$kind = is_string($file['kind'] ?? null) && in_array($file['kind'], self::KINDS, true) ? $file['kind'] : 'txt';
			$cut = mb_strlen($text) > self::FILE_MAX;
			$out[] = [
				'name' => static::name($file['name'] ?? ''),
				'kind' => $kind,
				'text' => $cut ? mb_substr($text, 0, self::FILE_MAX) : $text,
				'truncated' => $cut || ($file['truncated'] ?? false) === true,
			];
			if(count($out) >= self::PER_MESSAGE)
			{
				break;
			}
		}

		return $out;
	}

	/**
	 * Все файлы переписки с общим пределом: от свежих к старым, порядок в ответе —
	 * как в переписке. Не влезший целиком файл обрезается, остальные — прочь.
	 *
	 * @param list<list<array{name: string, kind: string, text: string, truncated: bool}>> $perMessage
	 * @return list<array{name: string, kind: string, text: string, truncated: bool}>
	 */
	public static function limit(array $perMessage): array
	{
		$left = self::TOTAL_MAX;
		$kept = [];
		// От свежих сообщений к старым, внутри сообщения — по порядку; в ответе — как в переписке
		foreach(array_reverse($perMessage) as $files)
		{
			$message = [];
			foreach($files as $file)
			{
				if($left <= 0)
				{
					break;
				}
				$length = mb_strlen($file['text']);
				if($length > $left)
				{
					$file['text'] = mb_substr($file['text'], 0, $left);
					$file['truncated'] = true;
					$length = $left;
				}
				$left -= $length;
				$message[] = $file;
			}
			array_unshift($kept, $message);
		}

		return array_merge(...$kept);
	}

	/** Имя файла: без пути и управляющих символов, до 120 знаков; пусто — «файл». */
	public static function name(mixed $raw): string
	{
		$name = is_string($raw) ? $raw : '';
		$name = preg_replace('~^.*[/\\\\]~u', '', $name) ?? '';
		$name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? '');

		return $name === '' ? 'файл' : mb_substr($name, 0, 120);
	}

	/**
	 * Блок для модели: каждый файл с именем и пометкой об обрезке.
	 *
	 * @param list<array{name: string, kind: string, text: string, truncated: bool}> $files
	 */
	public static function render(array $files): string
	{
		$blocks = [];
		foreach($files as $file)
		{
			$blocks[] = '# Файл «'.$file['name'].'» ('.strtoupper($file['kind']).')'
				.($file['truncated'] ? ' — показано начало, файл длиннее' : '')
				."\n".$file['text'];
		}

		return implode("\n\n---\n\n", $blocks);
	}
}
