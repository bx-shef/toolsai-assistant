<?php declare(strict_types=1);

/**
 * Роли из библиотеки промптов shef.toolsai 1.54.0 (Chat\Gateway\ToolsAiPromptSource):
 * список и сборка — для текущего сотрудника без своего сужения
 * (roles(null, -1), compose(…, null, -1)), проверка роли — isAvailable(),
 * иконка set:<имя> и картинка iconUrl, шаблон навыка «с уточнениями».
 * shef.toolsai старее 1.54.0 — отказ, а не роли всем.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Public\Service\PromptService;
use Shef\ToolsAiAssistant\Chat\ChatException;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiPromptSource;
use Shef\ToolsAiAssistant\Chat\RoleIcon;

Check::group('shef.toolsai без PromptService 1.54.0');

$code = '';
try
{
	(new ToolsAiPromptSource())->roles();
}
catch(ChatException $exception)
{
	$code = $exception->errorCode;
}
Check::same('отказ, а не роли всем', $code, ChatException::ACCESS);

require_once $root.'/tests/stub/promptservice.php';

Check::group('список ролей');

PromptService::$roles = [
	['code' => 'shef.toolsaiassistant:coach', 'title' => 'Наставник', 'description' => 'Про звонки', 'icon' => 'set:rocket', 'iconUrl' => '', 'favorite' => true],
	['code' => 'shef.toolsaiassistant:manager', 'title' => 'Помощник', 'icon' => 'file:12', 'iconUrl' => '/upload/shef/ab/icon.png', 'favorite' => false],
	['code' => 'shef.toolsai:x', 'title' => 'Чужая', 'icon' => '', 'iconUrl' => 'javascript:alert(1)'],
];
$roles = (new ToolsAiPromptSource())->roles();
Check::same('для текущего сотрудника, без своего сужения', PromptService::$calls[0], ['roles', null, PromptService::USER_CURRENT]);
Check::same('порядок библиотеки (избранные первыми) сохранён', array_column($roles, 'code'), ['shef.toolsaiassistant:coach', 'shef.toolsaiassistant:manager', 'shef.toolsai:x']);
Check::same('избранное — из библиотеки', array_column($roles, 'favorite'), [true, false, false]);
Check::same('иконка set:<имя> — имя ui.icon-set; file: — запасная по коду', array_column($roles, 'icon'), ['rocket', 'o-person', 'o-copilot']);
Check::same('картинка — только путь портала', array_column($roles, 'iconUrl'), ['', '/upload/shef/ab/icon.png', '']);

Check::group('проверка роли и сборка');

PromptService::$open = ['shef.toolsaiassistant:coach'];
PromptService::$calls = [];
$source = new ToolsAiPromptSource();
Check::same('isAvailable — для текущего сотрудника', [$source->isAvailable('shef.toolsaiassistant:coach'), $source->isAvailable('shef.toolsaiassistant:supervisor'), PromptService::$calls[0]], [true, false, ['isAvailable', 'shef.toolsaiassistant:coach', PromptService::USER_CURRENT]]);

PromptService::$composed = ['role' => 'shef.toolsaiassistant:coach', 'skill' => null, 'system' => 'СИСТЕМА', 'examples' => ['Что дальше?'], 'type' => 'plain'];
PromptService::$calls = [];
$composed = $source->compose('shef.toolsaiassistant:coach', null);
Check::same('compose — место чата, без записи, для текущего сотрудника', PromptService::$calls[0], ['compose', 'shef.toolsaiassistant:coach', 'assistant', 0, null, PromptService::USER_CURRENT]);
Check::same('обычный навык — шаблона нет', $composed, ['system' => 'СИСТЕМА', 'examples' => ['Что дальше?'], 'template' => '']);

PromptService::$composed = ['role' => 'shef.toolsaiassistant:coach', 'skill' => 'shef.toolsai:call.review', 'system' => 'СИСТЕМА', 'examples' => [], 'type' => 'template'];
PromptService::$prompts['shef.toolsai:call.review'] = ['text' => 'Разбери звонок с [клиент]'];
Check::same('навык «с уточнениями» — его текст в шаблон', $source->compose('shef.toolsaiassistant:coach', 2)['template'], 'Разбери звонок с [клиент]');

Check::group('адрес картинки роли');

Check::same('путь портала и https — да', [RoleIcon::url('/upload/a b.png'), RoleIcon::url('/upload/a.png'), RoleIcon::url('https://cdn.example/x.png')], ['', '/upload/a.png', 'https://cdn.example/x.png']);
Check::same('//хост, http, javascript, кавычки — нет', [RoleIcon::url('//evil/x.png'), RoleIcon::url('http://x/y.png'), RoleIcon::url('javascript:1'), RoleIcon::url('/a".png'), RoleIcon::url('/a).png')], ['', '', '', '', '']);

Check::finish();
