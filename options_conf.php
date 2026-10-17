<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAiAssistant\Main\Constants;

/**
 * Опции для страницы настроек модуля (options.php из shef.options — как есть).
 *
 * Языковой файл — lang/ru/options.php (его подключает options.php). Tab(prefix)->Option(code) ~>
 * код настройки prefix_code. Коды вкладок (PANEL, CHAT) и опций менять
 * нельзя — их читает Shef\ToolsAiAssistant\Config.
 */

$response = ShOptionsConfig::getInstance(
	moduleId: 'shef.toolsaiassistant'
);
if(!$response->isSuccess())
{
	return $response;
}

/** @var ShOptionsConfig $options */
$options = $response->getData()['OPTIONS'];

$options->addTab(
	(new Options\Tab('PANEL'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_PANEL_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_PANEL_TITLE'))
		->addOption(
			(new Options\RowInfo('RIGHTS'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_PANEL_RIGHTS', ['#URL#' => Constants::RIGHTS_URL, '#FEEDBACK_URL#' => Constants::FEEDBACK_URL]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Checkbox('enabled'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_PANEL_enabled'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_PANEL_enabled_descr'))
		)
		->addOption(
			(new Options\TextArea('paths'))
				->setRows(8)
				->setCols(80)
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_PANEL_paths'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_PANEL_paths_descr'))
		)
);

$options->addTab(
	(new Options\Tab('CHAT'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_CHAT_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_CHAT_TITLE'))
		->addOption(
			(new Options\NumberInt('history'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_CHAT_history'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_CHAT_history_descr'))
		)
		->addOption(
			(new Options\NumberInt('kblimit'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_CHAT_kblimit'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_CHAT_kblimit_descr'))
		)
		->addOption(
			(new Options\NumberInt('ratelimit'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_CHAT_ratelimit'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_CHAT_ratelimit_descr'))
		)
);

return $options->get();
