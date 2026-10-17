<?php declare(strict_types=1);

/**
 * Роли из библиотеки промптов shef.toolsai 1.54.0 (Chat\Gateway\ToolsAiPromptSource):
 * список и сборка — для явного сотрудника без своего сужения
 * (roles(null, 7), compose(…, null, 7)), не USER_CURRENT; сотрудника нет
 * (id <= 0) — отказ, а не «без проверки»; проверка роли — isAvailable(),
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
	(new ToolsAiPromptSource())->roles(7);
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
$roles = (new ToolsAiPromptSource())->roles(7);
Check::same('для этого сотрудника, без своего сужения', PromptService::$calls[0], ['roles', null, 7]);
Check::same('порядок библиотеки (избранные первыми) сохранён', array_column($roles, 'code'), ['shef.toolsaiassistant:coach', 'shef.toolsaiassistant:manager', 'shef.toolsai:x']);
Check::same('избранное — из библиотеки', array_column($roles, 'favorite'), [true, false, false]);
Check::same('иконка set:<имя> — имя ui.icon-set; file: — запасная по коду', array_column($roles, 'icon'), ['rocket', 'o-person', 'o-copilot']);
Check::same('картинка — только путь портала', array_column($roles, 'iconUrl'), ['', '/upload/shef/ab/icon.png', '']);

Check::group('проверка роли и сборка');

PromptService::$open = ['shef.toolsaiassistant:coach'];
PromptService::$calls = [];
$source = new ToolsAiPromptSource();
Check::same('isAvailable — для этого сотрудника', [$source->isAvailable('shef.toolsaiassistant:coach', 7), $source->isAvailable('shef.toolsaiassistant:supervisor', 7), PromptService::$calls[0]], [true, false, ['isAvailable', 'shef.toolsaiassistant:coach', 7]]);

$refused = static function(callable $call): string
{
	try
	{
		$call();
	}
	catch(ChatException $exception)
	{
		return $exception->errorCode;
	}

	return 'нет отказа';
};
PromptService::$calls = [];
Check::same('сотрудника нет (0, -1) — отказ, библиотеку не зовём (иначе USER_ANY — роли всем)', [
	$refused(static fn() => $source->roles(0)),
	$refused(static fn() => $source->isAvailable('shef.toolsaiassistant:coach', 0)),
	$refused(static fn() => $source->compose('shef.toolsaiassistant:coach', null, -1)),
	PromptService::$calls,
], [ChatException::ACCESS, ChatException::ACCESS, ChatException::ACCESS, []]);

PromptService::$calls = [];
Check::same('названия для фильтра оценок — без проверки доступа', [$source->titles(), PromptService::$calls[0]], [['shef.toolsaiassistant:coach' => 'Наставник', 'shef.toolsaiassistant:manager' => 'Помощник', 'shef.toolsai:x' => 'Чужая'], ['roles', null, PromptService::USER_ANY]]);

PromptService::$composed = ['role' => 'shef.toolsaiassistant:coach', 'skill' => null, 'system' => 'СИСТЕМА', 'examples' => ['Что дальше?'], 'type' => 'plain'];
PromptService::$calls = [];
$composed = $source->compose('shef.toolsaiassistant:coach', null, 7);
Check::same('compose — место чата, без записи, для этого сотрудника', PromptService::$calls[0], ['compose', 'shef.toolsaiassistant:coach', 'assistant', 0, null, 7]);
Check::same('обычный навык — шаблона нет; роль, которую взяла библиотека, — наружу', $composed, ['role' => 'shef.toolsaiassistant:coach', 'system' => 'СИСТЕМА', 'examples' => ['Что дальше?'], 'template' => '']);

PromptService::$composed = ['role' => 'shef.toolsaiassistant:coach', 'skill' => 'shef.toolsai:call.review', 'system' => 'СИСТЕМА', 'examples' => [], 'type' => 'template'];
PromptService::$prompts['shef.toolsai:call.review'] = ['text' => 'Разбери звонок с [клиент]'];
PromptService::$open = ['shef.toolsaiassistant:coach', 'shef.toolsai:call.review'];
Check::same('навык «с уточнениями» — его текст в шаблон', $source->compose('shef.toolsaiassistant:coach', 2, 7)['template'], 'Разбери звонок с [клиент]');
PromptService::$open = ['shef.toolsaiassistant:coach'];
PromptService::$calls = [];
Check::same('навык сотруднику не открыт — шаблона нет, get() не зовём', [$source->compose('shef.toolsaiassistant:coach', 2, 7)['template'], array_column(PromptService::$calls, 0)], ['', ['compose', 'isAvailable']]);

Check::group('адрес картинки роли');

Check::same('путь портала и https — да', [RoleIcon::url('/upload/a b.png'), RoleIcon::url('/upload/a.png'), RoleIcon::url('https://cdn.example/x.png')], ['', '/upload/a.png', 'https://cdn.example/x.png']);
Check::same('//хост, http, javascript, кавычки — нет', [RoleIcon::url('//evil/x.png'), RoleIcon::url('http://x/y.png'), RoleIcon::url('javascript:1'), RoleIcon::url('/a".png'), RoleIcon::url('/a).png')], ['', '', '', '', '']);

Check::finish();
