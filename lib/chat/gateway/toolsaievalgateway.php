<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat\Gateway;

use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Отзывы — в общий журнал shef.toolsaieval (1.12.0): мягко,
 * Loader::includeModule + class_exists. Нет модуля — isAvailable() false, и
 * отзывы пишутся в свою таблицу, как до 1.12.0.
 */
final class ToolsAiEvalGateway implements EvalGatewayInterface
{
	public function isAvailable(): bool
	{
		return Loader::includeModule(Constants::EVAL_MODULE_ID) && class_exists(Constants::EVAL_SERVICE_CLASS);
	}

	public function record(array $input): ?int
	{
		$class = Constants::EVAL_SERVICE_CLASS;

		return $class::record($input);
	}

	public function import(array $input, mixed $createdAt, int $sourceId, array $review): ?int
	{
		$class = Constants::EVAL_SERVICE_CLASS;

		return $class::import($input, $createdAt, $sourceId, $review);
	}
}
