<?php declare(strict_types=1);

/**
 * Настраиваемые параметры модуля shef.toolsaiassistant.
 *
 * * requireModules -> обязательные модули: autoload.php подключает их при
 *   каждой загрузке модуля, установщик их удаление не пропустит. shef.toolsai —
 *   жёстко: модель, квота, журнал расхода, реестр промптов и история записи CRM
 *   берутся у него (docs/guide/03-dependencies.md). shef.toolsaikb — мягко, его
 *   здесь нет: есть модуль — чат ищет статьи, нет — работает без них.
 * * requirePhpExt -> обязательные расширения PHP
 * * registerAutoLoadClasses -> авто подгрузка классов (не нужна)
 * * registerNamespace -> чужие namespace (своих классов здесь нет: ядро находит
 *   Shef\ToolsAiAssistant\... в lib/ по соглашению, путь строчными)
 * * options -> опции из окружения
 * * installEvents -> постоянные подписки на события (установщик регистрирует,
 *   при удалении снимает те же)
 * * installDir -> что копируется из install/ в публичную часть
 * * controllers -> контроллеры ajax: shef:toolsaiassistant.chat.answer и т.д.
 */

return [
	'requireModules' => [
		'value' => [
			'shef.options',
			'shef.problems',
			'shef.toolsai',
		],
		'readonly' => true,
	],
	'requirePhpExt' => [
		'value' => [
			'mbstring',
			'json',
		],
		'readonly' => true,
	],
	'registerAutoLoadClasses' => [
		'value' => [],
		'readonly' => true,
	],
	'registerNamespace' => [
		'value' => [],
		'readonly' => true,
	],
	'options' => [
		'value' => [],
		'readonly' => true,
	],
	'installEvents' => [
		'value' => [
			// 6 ролей помощника — в реестр промптов shef.toolsai (правятся в его «Библиотеке промптов»)
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnPromptRegistryBuild'],
				'to' => [
					'module' => 'shef.toolsaiassistant',
					'class' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\ToolsAi\\EventHandler\\OnPromptRegistryBuildEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// «✦ Спросить ИИ» в меню строки вида «ИИ» shef.toolsai (1.50.0+)
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnViewRowActionsBuild'],
				'to' => [
					'module' => 'shef.toolsaiassistant',
					'class' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\ToolsAi\\EventHandler\\OnViewRowActionsBuildEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// «✦ ИИ: чат» в шапке вида «ИИ» shef.toolsai штатным Toolbar API (1.53.0+, 1.3.1)
			[
				'isCompatible' => false,
				'from' => ['module' => 'shef.toolsai', 'event' => 'OnViewToolbarBuild'],
				'to' => [
					'module' => 'shef.toolsaiassistant',
					'class' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\ToolsAi\\EventHandler\\OnViewToolbarBuildEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// «✦ Спросить ИИ» в меню-шестерёнке карточки CRM (1.3.2)
			[
				'isCompatible' => false,
				'from' => ['module' => 'intranet', 'event' => 'onBuildBindingMenu'],
				'to' => [
					'module' => 'shef.toolsaiassistant',
					'class' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\Intranet\\EventHandler\\OnBuildBindingMenuEventHandler',
					'function' => 'handle',
					'sort' => 100,
				],
			],
			// Панель чата справа: расширение и кнопка — тем, кому открыт чат
			[
				'isCompatible' => true,
				'from' => ['module' => 'main', 'event' => 'OnProlog'],
				'to' => [
					'module' => 'shef.toolsaiassistant',
					'class' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\Main\\EventHandler\\OnPrologEventHandler',
					'function' => 'handle',
					'sort' => 500,
				],
			],
		],
		'readonly' => true,
	],
	'installDir' => [
		'value' => [
			// JS-расширения: shef.toolsaiassistant.chat -> /bitrix/js/shef/toolsaiassistant/chat/
			[
				'type' => 'js',
				'from' => '/install/js',
				'to' => '/bitrix/js',
				'customPathUnInstall' => ['/bitrix/js/shef/toolsaiassistant'],
				'isNeedUnInstall' => true,
			],
			// Компонент страницы прав (ui.accessrights.v2 сохраняет через ajax.php компонента)
			[
				'type' => 'components',
				'from' => '/install/components',
				'to' => '/local/components',
				'customPathUnInstall' => ['/local/components/shef/toolsaiassistant.rights'],
				'isNeedUnInstall' => true,
			],
			// Публичная страница «Права доступа»: /shef/toolsaiassistant/rights/
			[
				'type' => 'public',
				'from' => '/install/public',
				'to' => '',
				'customPathUnInstall' => ['/shef/toolsaiassistant'],
				'isNeedUnInstall' => true,
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			// BX.ajax.runAction('shef:toolsaiassistant.chat.answer') -> Controller\Chat::answerAction()
			'defaultNamespace' => '\\Shef\\ToolsAiAssistant\\Controller',
			'namespaces' => [],
		],
		'readonly' => true,
	],
	// Подбор мест «Где показывать чат» (1.3.0): провайдер ui.entity-selector
	'ui.entity-selector' => [
		'value' => [
			'entities' => [
				[
					'entityId' => 'shef-assistant-place',
					'provider' => [
						'moduleId' => 'shef.toolsaiassistant',
						'className' => '\\Shef\\ToolsAiAssistant\\Internals\\Integration\\Ui\\EntitySelector\\PlaceProvider',
					],
				],
			],
		],
		'readonly' => true,
	],
];
