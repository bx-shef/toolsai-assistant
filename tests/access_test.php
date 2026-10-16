<?php declare(strict_types=1);

/**
 * Права модуля: словарь, разрешение по ролям доступа, разбор формы страницы
 * прав. Здесь решается, увидит ли продавец роль руководителя.
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

Check::same('право на роль', Actions::roleRight('Shef.ToolsAiAssistant:Manager'), 'role:shef.toolsaiassistant:manager');
Check::same('роль из права', Actions::roleOf('role:'.MANAGER), MANAGER);
Check::same('мусор вместо кода роли — не право', Actions::roleOf('role:<script>'), null);
Check::same('без модуля в коде — не право', Actions::roleOf('role:manager'), null);
Check::same('chat.use известен', Actions::exists(Actions::CHAT_USE), true);
Check::same('неизвестное право', Actions::exists('chat.admin'), false);
Check::same(
	'роль тянет chat.use, мусор отброшен, порядок — постоянные, потом роли',
	Actions::normalize(['role:'.SUPERVISOR, 'x', 7, 'role:'.MANAGER, 'role:'.SUPERVISOR]),
	['chat.use', 'role:'.SUPERVISOR, 'role:'.MANAGER]
);

Check::group('права сотрудника');

$roles = [
	['codes' => ['DR5'], 'actions' => ['chat.use', 'role:'.MANAGER]],
	['codes' => ['U9'], 'actions' => ['role:'.SUPERVISOR]],
];

$seller = Rights::resolve(false, ['U1', 'DR5', 'D5'], $roles);
Check::same('продавец: чат открыт', $seller->canUseChat(), true);
Check::same('продавец: своя роль', $seller->canRole(MANAGER), true);
Check::same('продавец: роль руководителя закрыта', $seller->canRole(SUPERVISOR), false);
Check::same('продавец: открытые роли', $seller->allowedRoles(), [MANAGER]);

$boss = Rights::resolve(false, ['U9'], $roles);
Check::same('руководителю роль даёт и чат', $boss->canUseChat(), true);
Check::same('руководитель: только его роль', $boss->allowedRoles(), [SUPERVISOR]);

$stranger = Rights::resolve(false, ['U2', 'DR7'], $roles);
Check::same('чужой отдел: чата нет', $stranger->canUseChat(), false);
Check::same('чужой отдел: ролей нет', $stranger->allowedRoles(), []);

$admin = Rights::resolve(true, [], []);
Check::same('администратор: чат', $admin->canUseChat(), true);
Check::same('администратор: любая роль', $admin->canRole('shef.toolsai:my.role'), true);
Check::same('администратор: все роли (null)', $admin->allowedRoles(), null);

Check::same('нет прав: чата нет', Rights::none()->canUseChat(), false);

Check::group('форма страницы прав');

$form = RoleForm::parse([
	['id' => '3', 'title' => ' Продавец ', 'accessCodes' => ['DR5' => 'departments', 'U7' => 'users'], 'accessRights' => [
		['id' => 'role:'.MANAGER, 'value' => '1'],
		['id' => 'role:'.SUPERVISOR, 'value' => '0'],
		['id' => 'hack', 'value' => '1'],
	]],
	['id' => '0', 'title' => 'Новая', 'accessCodes' => [], 'accessRights' => []],
], ['4', 'x', '-1']);

Check::same('ошибок нет', $form['errors'], []);
Check::same('роль: id и название', [$form['roles'][0]['id'], $form['roles'][0]['title']], [3, 'Продавец']);
Check::same('роль: коды', $form['roles'][0]['codes'], ['DR5', 'U7']);
Check::same('роль: права — только включённые и известные, chat.use добавлен', $form['roles'][0]['actions'], ['chat.use', 'role:'.MANAGER]);
Check::same('новая роль — id 0', $form['roles'][1]['id'], 0);
Check::same('удалённые — только целые', $form['deleted'], [4]);

$bad = RoleForm::parse([['title' => 'Группа', 'accessCodes' => ['G2' => 'groups', 'D5' => 'departments']]], []);
Check::same('группа сайта и отдел без подотделов — ошибка, а не молчаливый пропуск', count($bad['errors']), 2);
$noTitle = RoleForm::parse([['title' => '  ']], []);
Check::same('без названия — ошибка', $noTitle['errors'], ['У роли должно быть название']);

Check::finish();
