<?php declare(strict_types=1);

/**
 * Права модуля: словарь, разрешение по ролям доступа, разбор формы страницы
 * прав. Роли помощника (1.6.0) здесь не раздаются — их открывает библиотека
 * промптов shef.toolsai; старые права role:<код> в базе просто не читаются.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Access\Actions;
use Shef\ToolsAiAssistant\Access\RoleForm;
use Shef\ToolsAiAssistant\Access\Rights;

const MANAGER = 'shef.toolsaiassistant:manager';
const SUPERVISOR = 'shef.toolsaiassistant:supervisor';

Check::group('словарь прав');

Check::same('chat.use известен', Actions::exists(Actions::CHAT_USE), true);
Check::same('неизвестное право', Actions::exists('chat.admin'), false);
Check::same('старое право на роль — не право (1.6.0)', Actions::exists('role:'.MANAGER), false);
Check::same(
	'старые права ролей и мусор отброшены, feedback.edit тянет feedback.view, порядок — как в словаре',
	Actions::normalize(['role:'.SUPERVISOR, 'x', 7, 'feedback.edit', 'chat.use', 'chat.use']),
	['chat.use', 'feedback.view', 'feedback.edit']
);
Check::same('раздел прав один — чат', array_keys(Actions::GROUPS), ['chat']);

Check::group('права сотрудника');

$roles = [
	['codes' => ['DR5'], 'actions' => ['chat.use', 'role:'.MANAGER]],
	['codes' => ['U9'], 'actions' => ['role:'.SUPERVISOR]],
];

$seller = Rights::resolve(false, ['U1', 'DR5', 'D5'], $roles);
Check::same('продавец: чат открыт', $seller->canUseChat(), true);
Check::same('продавец: старые права ролей не читаются', $seller->getActions(), ['chat.use']);

$boss = Rights::resolve(false, ['U9'], $roles);
Check::same('старое право роли больше не открывает чат', $boss->canUseChat(), false);

$stranger = Rights::resolve(false, ['U2', 'DR7'], $roles);
Check::same('чужой отдел: чата нет', $stranger->canUseChat(), false);

$admin = Rights::resolve(true, [], []);
Check::same('администратор: чат и оценки', [$admin->canUseChat(), $admin->canViewFeedback(), $admin->canEditFeedback()], [true, true, true]);

Check::same('нет прав: чата нет', Rights::none()->canUseChat(), false);

Check::group('форма страницы прав');

$form = RoleForm::parse([
	['id' => '3', 'title' => ' Продавец ', 'accessCodes' => ['DR5' => 'departments', 'U7' => 'users'], 'accessRights' => [
		['id' => 'chat.use', 'value' => '1'],
		['id' => 'role:'.MANAGER, 'value' => '1'],
		['id' => 'feedback.view', 'value' => '0'],
		['id' => 'hack', 'value' => '1'],
	]],
	['id' => '0', 'title' => 'Новая', 'accessCodes' => [], 'accessRights' => []],
], ['4', 'x', '-1']);

Check::same('ошибок нет', $form['errors'], []);
Check::same('роль: id и название', [$form['roles'][0]['id'], $form['roles'][0]['title']], [3, 'Продавец']);
Check::same('роль: коды', $form['roles'][0]['codes'], ['DR5', 'U7']);
Check::same('роль: права — только включённые и известные, старое право роли отброшено', $form['roles'][0]['actions'], ['chat.use']);
Check::same('новая роль — id 0', $form['roles'][1]['id'], 0);
Check::same('удалённые — только целые', $form['deleted'], [4]);

$bad = RoleForm::parse([['title' => 'Группа', 'accessCodes' => ['G2' => 'groups', 'D5' => 'departments']]], []);
Check::same('группа сайта и отдел без подотделов — ошибка, а не молчаливый пропуск', count($bad['errors']), 2);
$noTitle = RoleForm::parse([['title' => '  ']], []);
Check::same('без названия — ошибка', $noTitle['errors'], ['У роли должно быть название']);

Check::finish();
