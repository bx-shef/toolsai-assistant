<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

/**
 * Разбор оценки (1.4.0): что с ней сделали и что должно было быть в ответе.
 *
 * Оценка — сырьё для проверки промптов shef.toolsai (docs/private/prompt-evals.md
 * там): «не помогло» → человек пишет, что должно было быть → оценка уходит
 * кейсом проверки (EvalCase) → прогон проверки промптов по роли.
 *
 *   new      — новая, ещё не смотрели;
 *   reviewed — разобрана: посмотрели, кейсом не берём (или ещё допишем);
 *   eval     — в наборе проверки: выгружена кейсом (EvalCase);
 *   rejected — отклонена: оценка случайная, не про ответ, дубль.
 *
 * «Что должно было быть» (EXPECTED) — по факту на строку, как факты судьи в
 * кейсах shef.toolsai: варианты одного факта — через «|» («20 | двадцать»),
 * строка с «!» в начале — чего в ответе быть не должно (forbidden).
 *
 * Чистый класс — без ядра (feedback_test.php).
 */
final class Review
{
	public const NEW = 'new';
	public const REVIEWED = 'reviewed';
	public const EVAL = 'eval';
	public const REJECTED = 'rejected';

	public const STATUSES = [
		self::NEW => 'Новая',
		self::REVIEWED => 'Разобрана',
		self::EVAL => 'В наборе проверки',
		self::REJECTED => 'Отклонена',
	];

	/** Статусы, которые ставят руками групповым действием («в наборе» — только выгрузкой). */
	public const MANUAL = [self::REVIEWED, self::REJECTED, self::NEW];

	/** Сколько знаков «что должно было быть» хранить. */
	public const EXPECTED_MAX = 4000;
	/** Сколько оценок за раз — групповое действие, выгрузка. */
	public const BATCH_MAX = 200;

	/** Код статуса, мусор — null. */
	public static function status(mixed $value): ?string
	{
		return is_string($value) && isset(self::STATUSES[$value]) ? $value : null;
	}

	/** Подпись статуса; неизвестный — «Новая» (старые строки до 1.4.0). */
	public static function title(?string $status): string
	{
		return self::STATUSES[$status ?? ''] ?? self::STATUSES[self::NEW];
	}

	/** «Что должно было быть»: без управляющих символов, строки подчищены, до EXPECTED_MAX знаков. */
	public static function expected(mixed $value): string
	{
		$value = is_scalar($value) ? (string)$value : '';
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		$value = (string)preg_replace('/[^\P{C}\n]+/u', '', $value);
		$lines = array_map('trim', explode("\n", $value));
		$value = trim(implode("\n", $lines));
		$value = (string)preg_replace('/\n{3,}/', "\n\n", $value);

		return mb_strlen($value) > self::EXPECTED_MAX ? rtrim(mb_substr($value, 0, self::EXPECTED_MAX)) : $value;
	}

	/**
	 * Факты из «что должно было быть» — в формате expect кейса shef.toolsai.
	 *
	 * @return array{facts: list<list<string>>, forbidden: list<string>}
	 */
	public static function facts(string $expected): array
	{
		$facts = [];
		$forbidden = [];
		foreach(explode("\n", $expected) as $line)
		{
			$line = trim((string)preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line));
			if($line === '')
			{
				continue;
			}
			if(str_starts_with($line, '!'))
			{
				$line = trim(substr($line, 1));
				if($line !== '')
				{
					$forbidden[] = $line;
				}
				continue;
			}
			$variants = array_values(array_filter(array_map('trim', explode('|', $line)), static fn(string $v): bool => $v !== ''));
			if($variants !== [])
			{
				$facts[] = $variants;
			}
		}

		return ['facts' => $facts, 'forbidden' => $forbidden];
	}

	/**
	 * ID оценок из запроса: целые больше нуля, без повторов, не больше BATCH_MAX.
	 *
	 * @return list<int>
	 */
	public static function ids(mixed $raw): array
	{
		$ids = [];
		foreach(is_array($raw) ? $raw : [$raw] as $id)
		{
			if(is_scalar($id) && ctype_digit((string)$id) && (int)$id > 0)
			{
				$ids[(int)$id] = (int)$id;
			}
		}

		return array_slice(array_values($ids), 0, self::BATCH_MAX);
	}
}
