<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

/**
 * Иконка роли (1.4.0) — класс штатного набора ui.icon-set (outline):
 * «ui-icon-set --<icon>». Реестр shef.toolsai может отдать свою в поле icon
 * роли; нет или не похожа на код иконки — подбираем по коду роли.
 */
final class RoleIcon
{
	public const DEFAULT = 'o-copilot';
	/** Часть кода роли → иконка; первое совпадение. */
	public const BY_CODE = [
		'supervisor' => 'o-crown',
		'manager' => 'o-person',
		'deal.quality' => 'o-check-list',
		'quality' => 'o-check-list',
		'bottleneck' => 'o-bottleneck',
		'coach' => 'o-idea-lamp',
		'customer' => 'o-client-chat',
		'docs' => 'o-knowledge-base',
		'assistant' => 'o-ai-stars',
		'analy' => 'o-graphs-diagram',
		'sale' => 'o-handshake',
	];

	public static function of(string $code, mixed $given = null): string
	{
		if(is_string($given) && preg_match('/^[a-z][a-z0-9-]{1,60}$/', $given) === 1)
		{
			return $given;
		}
		$lower = strtolower($code);
		foreach(self::BY_CODE as $part => $icon)
		{
			if(str_contains($lower, $part))
			{
				return $icon;
			}
		}

		return self::DEFAULT;
	}
}
