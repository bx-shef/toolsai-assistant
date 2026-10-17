<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Agent;

/**
 * Инструменты MCP для агента (1.9.0): описания с сервера → короткий
 * проверенный список для модели и правила других модулей.
 *
 * Инструмент — только для чтения, если сервер пометил его
 * annotations.readOnlyHint = true; всё остальное считается записью и
 * выполняется только после «Выполнить» в чате.
 *
 * Правила модулей — ответ на событие shef.toolsaiassistant:OnAgentToolsCollect
 * (docs/guide/04-events.md):
 *   ['hide' => ['имя', …], 'confirm' => ['имя', …], 'hints' => ['имя' => 'подсказка модели']]
 * hide — убрать инструмент из чата; confirm — спрашивать и на чтение;
 * hints — дописать к описанию (как пользоваться на этом портале).
 * Добавить инструмент модуль не может — инструменты даёт MCP-сервер
 * (свои — манифестом модуля, MODULE_MANIFESTS toolsai-mcp).
 *
 * Чистый класс (agent_test.php).
 */
final class ToolCatalog
{
	public const EVENT = 'OnAgentToolsCollect';
	public const DESCRIPTION_MAX = 400;
	public const PARAM_MAX = 150;
	public const MAX_TOOLS = 80;

	/**
	 * @param list<array<string, mixed>> $raw ответ tools/list
	 * @param list<array{hide?: mixed, confirm?: mixed, hints?: mixed}> $rules ответы модулей
	 * @return array<string, array{name: string, title: string, description: string, schema: array<string, mixed>, readOnly: bool, confirm: bool}>
	 */
	public static function build(array $raw, array $rules = []): array
	{
		$hide = [];
		$confirm = [];
		$hints = [];
		foreach($rules as $rule)
		{
			foreach(static::names($rule['hide'] ?? null) as $name)
			{
				$hide[$name] = true;
			}
			foreach(static::names($rule['confirm'] ?? null) as $name)
			{
				$confirm[$name] = true;
			}
			foreach(is_array($rule['hints'] ?? null) ? $rule['hints'] : [] as $name => $hint)
			{
				if(is_string($name) && static::validName($name) && is_string($hint) && trim($hint) !== '')
				{
					$hints[$name][] = static::clean($hint, 300);
				}
			}
		}

		$out = [];
		foreach($raw as $tool)
		{
			$name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
			if(!static::validName($name) || isset($hide[$name]) || isset($out[$name]))
			{
				continue;
			}
			$annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
			$readOnly = ($annotations['readOnlyHint'] ?? false) === true;
			$description = static::clean(is_string($tool['description'] ?? null) ? $tool['description'] : '', self::DESCRIPTION_MAX);
			if(isset($hints[$name]))
			{
				$description = trim($description.' '.implode(' ', $hints[$name]));
			}
			$out[$name] = [
				'name' => $name,
				'title' => static::clean(is_string($tool['title'] ?? null) ? $tool['title'] : (is_string($annotations['title'] ?? null) ? $annotations['title'] : $name), 120),
				'description' => $description,
				'schema' => static::schema(is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : []),
				'readOnly' => $readOnly,
				'confirm' => isset($confirm[$name]),
			];
			if(count($out) >= self::MAX_TOOLS)
			{
				break;
			}
		}

		return $out;
	}

	/**
	 * Короткая схема параметров для модели: имя => тип, варианты, описание; обязательные — «required».
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(array $input): array
	{
		$props = [];
		foreach(is_array($input['properties'] ?? null) ? $input['properties'] : [] as $name => $prop)
		{
			if(!is_string($name) || !is_array($prop))
			{
				continue;
			}
			$short = [];
			foreach(['type', 'enum', 'format', 'minimum', 'maximum'] as $key)
			{
				if(isset($prop[$key]))
				{
					$short[$key] = $prop[$key];
				}
			}
			if(is_array($prop['items'] ?? null) && isset($prop['items']['type']))
			{
				$short['items'] = ['type' => $prop['items']['type']];
			}
			if(is_string($prop['description'] ?? null) && $prop['description'] !== '')
			{
				$short['description'] = static::clean($prop['description'], self::PARAM_MAX);
			}
			$props[$name] = $short;
		}
		$schema = ['properties' => $props];
		$required = array_values(array_filter(is_array($input['required'] ?? null) ? $input['required'] : [], 'is_string'));
		if($required !== [])
		{
			$schema['required'] = $required;
		}

		return $schema;
	}

	/** Нужно ли подтверждение сотрудника перед вызовом. */
	public static function needsConfirm(array $tool, bool $autoRead): bool
	{
		return !$tool['readOnly'] || $tool['confirm'] || !$autoRead;
	}

	public static function validName(string $name): bool
	{
		return preg_match('/^[a-zA-Z0-9_.\-]{1,64}$/', $name) === 1;
	}

	/** @return list<string> */
	private static function names(mixed $list): array
	{
		return array_values(array_filter(is_array($list) ? $list : [], static fn($n): bool => is_string($n) && static::validName($n)));
	}

	private static function clean(string $text, int $max): string
	{
		$text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));

		return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
	}
}
