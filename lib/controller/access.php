<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Shef\ToolsAiAssistant\Access\RightsPage;
use Shef\ToolsAiAssistant\Access\RoleForm;
use Shef\ToolsAiAssistant\Access\RoleStore;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Container;

/**
 * Сохранение страницы «Права доступа» (ui.accessrights.v2).
 *
 * Зовёт BX.ajax.runComponentAction('shef:toolsaiassistant.rights', 'save',
 * {mode: 'ajax', data: {userGroups, deletedUserGroups}}): ядро подключает
 * ajax.php компонента (main/lib/engine/router.php) — там наследник этого
 * класса. Только POST, sessid и администратор портала.
 */
class Access extends Controller
{
	public function configureActions(): array
	{
		return [
			'save' => [
				'prefilters' => [
					new ActionFilter\Authentication(),
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	/** Сохранить роли доступа целиком; ответ — роли заново (USER_GROUPS). */
	public function saveAction(array $userGroups = [], array $deletedUserGroups = [], array $parameters = []): ?array
	{
		if(!$this->getCurrentUser()?->isAdmin())
		{
			$this->addError(new Error('Права доступа меняет только администратор портала'));

			return null;
		}
		if(!Loader::includeModule('ui'))
		{
			$this->addError(new Error('Нужен модуль ui'));

			return null;
		}

		$form = RoleForm::parse($userGroups, $deletedUserGroups);
		if($form['errors'] !== [])
		{
			foreach($form['errors'] as $message)
			{
				$this->addError(new Error($message));
			}

			return null;
		}

		try
		{
			RoleStore::save($form);
			UserAccess::reset();

			return ['USER_GROUPS' => RightsPage::userGroupsArray()];
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
			$this->addError(new Error('Не удалось сохранить права доступа'));

			return null;
		}
	}
}
