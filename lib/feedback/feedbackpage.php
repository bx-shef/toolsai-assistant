<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Bitrix\Main\UserTable;
use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiPromptSource;
use Shef\ToolsAiAssistant\Container;

/**
 * Страница «Оценки ответов»: /shef/toolsaiassistant/feedback/.
 *
 * С 1.5.0 — штатные main.ui.filter (период, оценка, статус, роль, сотрудник,
 * чат / запись; GridFilter) и main.ui.grid (постраничка Main\UI\PageNavigation,
 * сортировка и настройка колонок — Grid\Options ядра). Над гридом — сводка
 * «доля полезно по ролям» со ссылкой «Править промпт роли» (RoleSummary).
 * Вопрос и ответ в ячейке — кратко, целиком — окном оценки по щелчку
 * (JS-расширение shef.toolsaiassistant.feedback: ответ — тем же markdown.js,
 * что окно чата).
 *
 * Разбор (право feedback.edit): статус, «что должно было быть» — в окне
 * оценки; групповые действия грида «Разобрано», «Отклонить», «Выгрузить как
 * кейсы проверки» — контроллер Controller\Feedback.
 *
 * Право смотреть — feedback.view (администратору портала — всегда). Вёрстка —
 * чистые методы (gridRows, summary, columns, actionPanel, clientRows): всё,
 * что пришло от сотрудников и модели, экранируется (feedback_test.php).
 */
final class FeedbackPage
{
	public const URL = \Shef\ToolsAiAssistant\Main\Constants::FEEDBACK_URL;
	/** JS-расширение страницы: /bitrix/js/shef/toolsaiassistant/feedback/. */
	public const EXTENSION = 'shef.toolsaiassistant.feedback';
	/** Сколько знаков вопроса и ответа показывать в ячейке. */
	public const PREVIEW_MAX = 160;
	public const PAGE_SIZES = [20, 50, 100, 200];

	/** Вывести страницу для текущего пользователя. */
	public static function show(): void
	{
		$rights = UserAccess::current();
		if(!$rights->canViewFeedback())
		{
			echo static::alert('Нет доступа к оценкам ответов — право «Смотреть оценки ответов» выдаёт администратор портала на странице прав модуля.');

			return;
		}
		try
		{
			echo static::evalNotice();
			static::output($rights);
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
			echo static::alert('Не удалось прочитать оценки — подробности в журнале модуля.');
		}
	}

	/**
	 * Есть shef.toolsaieval (1.11.0): новые оценки пишутся туда, старые
	 * переносятся (EvalMigration); здесь — история своей таблицы и ссылка на
	 * общий журнал. Нет модуля — пусто, страница как раньше.
	 */
	private static function evalNotice(): string
	{
		$eval = new \Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiEvalGateway();
		if(!$eval->isAvailable())
		{
			return '';
		}
		try
		{
			EvalMigration::run($eval);
			$left = EvalMigration::left();
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
			$left = -1;
		}

		return static::noticeHtml(static::evalUrl(), $left);
	}

	/** Адрес страницы отзывов shef.toolsaieval. */
	private static function evalUrl(): string
	{
		$class = \Shef\ToolsAiAssistant\Main\Constants::EVAL_SERVICE_CLASS;

		return method_exists($class, 'pageUrl') ? (string)$class::pageUrl() : '/shef/toolsaieval/feedback/';
	}

	/**
	 * Подсказка о переходе на общий журнал. Чистая часть.
	 *
	 * @param int $left сколько оценок ещё не перенесено; -1 — перенос не удался
	 */
	public static function noticeHtml(string $url, int $left): string
	{
		$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$state = match(true) {
			$left < 0 => ' Перенос старых оценок не удался — подробности в журнале модуля, агент повторит.',
			$left > 0 => ' Старые оценки переносятся туда частями, осталось: '.$left.'.',
			default => ' Старые оценки перенесены туда же.',
		};
		$link = str_starts_with($url, '/') && !str_starts_with($url, '//') ? '<a href="'.$h($url).'">общем журнале отзывов</a>' : 'общем журнале отзывов';

		return '<div class="ui-alert ui-alert-primary"><span class="ui-alert-message">Новые оценки и их разбор — в '.$link.' (shef.toolsaieval).'.$h($state).' Ниже — история оценок этого модуля.</span></div>';
	}

	/** Фильтр, сводка, грид и данные окна оценки. */
	private static function output(Rights $rights): void
	{
		global $APPLICATION;

		$roles = (new ToolsAiPromptSource())->titles();

		$filterParams = [
			'FILTER_ID' => GridFilter::FILTER_ID,
			'GRID_ID' => GridFilter::GRID_ID,
			'FILTER' => GridFilter::fields($roles),
			'ENABLE_LIVE_SEARCH' => false,
			'DISABLE_SEARCH' => true,
			'ENABLE_LABEL' => true,
			'RESET_TO_DEFAULT_MODE' => true,
		];
		$values = GridFilter::values((new \Bitrix\Main\UI\Filter\Options(GridFilter::FILTER_ID))->getFilter($filterParams['FILTER']));

		$gridOptions = new \Bitrix\Main\Grid\Options(GridFilter::GRID_ID);
		$order = GridFilter::sort($gridOptions->getSorting(['sort' => ['CREATED_AT' => 'desc']])['sort']);
		$nav = new \Bitrix\Main\UI\PageNavigation(GridFilter::GRID_ID);
		$nav->allowAllRecords(false)->setPageSize((int)($gridOptions->GetNavParams()['nPageSize'] ?? 50))->initFromUri();

		$store = new FeedbackStore();
		$filter = GridFilter::orm($values);
		$total = $store->count($filter);
		$nav->setRecordCount($total);
		$rows = $store->list($nav->getLimit(), $nav->getOffset(), $filter, $order);
		$users = static::userNames(array_merge(array_column($rows, 'USER_ID'), array_column($rows, 'REVIEWED_BY')));
		$summary = RoleSummary::build($store->countByRole(GridFilter::orm($values, ['rating', 'role', 'status'])), $roles);
		$recordUrl = static::recordUrlResolver();
		$canEdit = $rights->canEditFeedback();

		if(class_exists(\Bitrix\UI\Toolbar\Facade\Toolbar::class))
		{
			\Bitrix\UI\Toolbar\Facade\Toolbar::addFilter($filterParams);
		}
		else
		{
			$APPLICATION->IncludeComponent('bitrix:main.ui.filter', '', $filterParams);
		}

		echo static::summary($summary, $values);
		echo '<p class="shef-aia-fb-note">Вопрос и ответ присылает окно чата вместе с оценкой — это текст глазами сотрудника, а не запись сервера. В них бывают фрагменты записей CRM. Оценки старше срока из настроек удаляются автоматически.'
			.($canEdit ? ' Что делать с оценкой: откройте её, напишите, что должно было быть в ответе (по факту на строку), и выгрузите отмеченные кнопкой «Выгрузить как кейсы проверки» — это набор для проверки промптов shef.toolsai.' : '')
			.'</p>';

		$APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
			'GRID_ID' => GridFilter::GRID_ID,
			'COLUMNS' => static::columns(),
			'ROWS' => static::gridRows($rows, $roles, $users, $recordUrl),
			'NAV_OBJECT' => $nav,
			'TOTAL_ROWS_COUNT' => $total,
			'AJAX_MODE' => 'N',
			'SHOW_ROW_CHECKBOXES' => $canEdit,
			'SHOW_CHECK_ALL_CHECKBOXES' => $canEdit,
			'SHOW_SELECTED_COUNTER' => $canEdit,
			'SHOW_ACTION_PANEL' => $canEdit,
			'ACTION_PANEL' => $canEdit ? static::actionPanel() : [],
			'SHOW_ROW_ACTIONS_MENU' => true,
			'SHOW_GRID_SETTINGS_MENU' => true,
			'SHOW_NAVIGATION_PANEL' => true,
			'SHOW_PAGINATION' => true,
			'SHOW_PAGESIZE' => true,
			'SHOW_TOTAL_COUNTER' => true,
			'PAGE_SIZES' => array_map(static fn(int $size): array => ['NAME' => (string)$size, 'VALUE' => (string)$size], self::PAGE_SIZES),
			'ALLOW_COLUMNS_SORT' => true,
			'ALLOW_COLUMNS_RESIZE' => true,
			'ALLOW_SORT' => true,
			'ALLOW_PIN_HEADER' => true,
			'STUB' => $rows === [] ? ['title' => 'Оценок нет', 'description' => $values === [] ? 'Сотрудники ставят их кнопками «Полезно» и «Не помогло» под ответом помощника.' : 'Под фильтр ничего не попало.'] : null,
		]);

		echo '<script>BX.ready(function(){BX.Shef.ToolsAiAssistant.Feedback.init('
			.static::json(['gridId' => GridFilter::GRID_ID, 'filterId' => GridFilter::FILTER_ID, 'canEdit' => $canEdit, 'statuses' => Review::STATUSES, 'rows' => static::clientRows($rows, $roles, $users, $recordUrl)])
			.');});</script>';
	}

	/** «up» — полезно, «down» — не помогло, прочее — все. */
	public static function ratingFilter(string $value): ?int
	{
		return match($value) {
			'up' => Rating::UP,
			'down' => Rating::DOWN,
			default => null,
		};
	}

	/** Колонки грида: по умолчанию — без «Кто разобрал» (включается в настройке колонок). */
	public static function columns(): array
	{
		return [
			['id' => 'CREATED_AT', 'name' => 'Когда', 'sort' => 'CREATED_AT', 'default' => true],
			['id' => 'USER', 'name' => 'Кто', 'sort' => 'USER', 'default' => true],
			['id' => 'ROLE', 'name' => 'Роль', 'sort' => 'ROLE', 'default' => true],
			['id' => 'CHAT', 'name' => 'Чат', 'default' => true],
			['id' => 'RATING', 'name' => 'Оценка', 'sort' => 'RATING', 'default' => true],
			['id' => 'STATUS', 'name' => 'Статус', 'sort' => 'STATUS', 'default' => true],
			['id' => 'QUESTION', 'name' => 'Вопрос', 'default' => true],
			['id' => 'ANSWER', 'name' => 'Ответ', 'default' => true],
			['id' => 'COMMENT', 'name' => 'Комментарий', 'default' => true],
			['id' => 'EXPECTED', 'name' => 'Что должно было быть', 'default' => true],
			['id' => 'REVIEWED', 'name' => 'Кто разобрал', 'default' => false],
		];
	}

	/**
	 * Строки грида: ячейки — готовый HTML, всё чужое экранировано.
	 *
	 * @param list<array<string, mixed>> $rows строки FeedbackStore::list()
	 * @param array<string, string> $roles код роли => название
	 * @param array<int, string> $users ID сотрудника => имя
	 * @param ?\Closure(int, int): ?string $recordUrl адрес записи CRM по типу и ID
	 * @return list<array{id: int, data: array<string, mixed>, columns: array<string, string>, actions: list<array<string, mixed>>}>
	 */
	public static function gridRows(array $rows, array $roles, array $users, ?\Closure $recordUrl = null): array
	{
		$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$out = [];
		foreach($rows as $row)
		{
			$id = (int)$row['ID'];
			$userId = (int)$row['USER_ID'];
			$role = (string)$row['ROLE_CODE'];
			$status = Review::status($row['STATUS'] ?? null) ?? Review::NEW;
			$open = static fn(string $text): string => '<a href="#" class="shef-aia-fb-open" data-id="'.$id.'">'.$text.'</a>';
			$expected = trim((string)($row['EXPECTED'] ?? ''));
			$reviewedBy = (int)($row['REVIEWED_BY'] ?? 0);
			$out[] = [
				'id' => $id,
				'data' => ['ID' => $id],
				'columns' => [
					'CREATED_AT' => $h(static::date($row['CREATED_AT'] ?? null)),
					'USER' => $h($users[$userId] ?? ('#'.$userId)),
					'ROLE' => $h($roles[$role] ?? $role),
					'CHAT' => static::chatCell((string)$row['CHAT_KEY'], $recordUrl),
					'RATING' => ((int)$row['RATING'] === Rating::UP ? '👍 ' : '👎 ').$h(Rating::title((int)$row['RATING'])),
					'STATUS' => '<span class="shef-aia-fb-status shef-aia-fb-status-'.$h($status).'">'.$h(Review::title($status)).'</span>',
					'QUESTION' => $open($h(static::preview((string)$row['QUESTION']))),
					'ANSWER' => $open($h(static::preview((string)$row['ANSWER']))),
					'COMMENT' => $h(static::preview((string)($row['COMMENT'] ?? ''))),
					'EXPECTED' => $expected !== '' ? $h(static::preview($expected)) : '<span class="shef-aia-fb-empty">—</span>',
					'REVIEWED' => $reviewedBy > 0 ? $h(($users[$reviewedBy] ?? '#'.$reviewedBy).', '.static::date($row['REVIEWED_AT'] ?? null)) : '',
				],
				'actions' => [
					['text' => 'Открыть', 'default' => true, 'onclick' => 'BX.Shef.ToolsAiAssistant.Feedback.open('.$id.')'],
				],
			];
		}

		return $out;
	}

	/**
	 * Данные окна оценки: вопрос, ответ, комментарий целиком (как есть — окно
	 * экранирует само, ответ — через markdown.js), статус, ожидаемое.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, string> $roles
	 * @param array<int, string> $users
	 * @param ?\Closure(int, int): ?string $recordUrl
	 * @return array<int, array<string, mixed>>
	 */
	public static function clientRows(array $rows, array $roles, array $users, ?\Closure $recordUrl = null): array
	{
		$out = [];
		foreach($rows as $row)
		{
			$id = (int)$row['ID'];
			$chatKey = (string)$row['CHAT_KEY'];
			$role = (string)$row['ROLE_CODE'];
			$out[$id] = [
				'id' => $id,
				'created' => static::date($row['CREATED_AT'] ?? null),
				'user' => $users[(int)$row['USER_ID']] ?? ('#'.(int)$row['USER_ID']),
				'role' => $role,
				'roleTitle' => $roles[$role] ?? $role,
				'promptUrl' => RoleSummary::promptUrl($role),
				'chat' => static::chatTitle($chatKey),
				'recordUrl' => static::recordUrl($chatKey, $recordUrl),
				'rating' => (int)$row['RATING'],
				'ratingTitle' => Rating::title((int)$row['RATING']),
				'status' => Review::status($row['STATUS'] ?? null) ?? Review::NEW,
				'question' => (string)$row['QUESTION'],
				'answer' => (string)$row['ANSWER'],
				'comment' => (string)($row['COMMENT'] ?? ''),
				'expected' => (string)($row['EXPECTED'] ?? ''),
			];
		}

		return $out;
	}

	/**
	 * Сводка «доля полезно по ролям» над гридом.
	 *
	 * @param list<array{code: string, title: string, up: int, down: int, total: int, share: float, reliable: bool, url: string}> $summary RoleSummary::build()
	 * @param array<string, mixed> $values GridFilter::values() — для подписи периода
	 */
	public static function summary(array $summary, array $values): string
	{
		$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$period = isset($values['from']) || isset($values['to'])
			? 'за период'.(isset($values['from']) ? ' с '.static::ymd((string)$values['from']) : '').(isset($values['to']) ? ' по '.static::ymd((string)$values['to']) : '')
			: 'за всё время хранения';
		$out = '<div class="shef-aia-fb-summary"><div class="shef-aia-fb-summary-title">Доля «полезно» по ролям '.$h($period).'</div>';
		if($summary === [])
		{
			return $out.'<p class="shef-aia-fb-note">Оценок под фильтр нет.</p></div>';
		}
		$out .= '<table class="shef-aia-fb-summary-table"><tr><th>Роль</th><th>Полезно</th><th>Не помогло</th><th>Доля «полезно»</th><th></th></tr>';
		foreach($summary as $item)
		{
			$tone = !$item['reliable'] ? 'thin' : ($item['share'] >= 80 ? 'good' : ($item['share'] >= 60 ? 'mid' : 'bad'));
			$out .= '<tr>'
				.'<td>'.$h($item['title']).'</td>'
				.'<td>'.(int)$item['up'].'</td>'
				.'<td>'.(int)$item['down'].'</td>'
				.'<td><span class="shef-aia-fb-share shef-aia-fb-share-'.$tone.'">'.$h(rtrim(rtrim(number_format((float)$item['share'], 1, '.', ''), '0'), '.')).'%</span>'
				.(!$item['reliable'] ? ' <span class="shef-aia-fb-note">мало оценок</span>' : '').'</td>'
				.'<td><a href="'.$h($item['url']).'" target="_blank">Править промпт роли</a></td>'
				.'</tr>';
		}

		return $out.'</table><p class="shef-aia-fb-note">Сверху — роли, где помощник помогает реже. Правка роли — в «Библиотеке промптов» shef.toolsai; проверить правку — прогоном проверки промптов по кейсам, выгруженным отсюда.</p></div>';
	}

	/**
	 * Панель групповых действий грида (как Grid\Panel\Snippet ядра: кнопки с
	 * действием CALLBACK, main/lib/grid/panel/snippet.php).
	 *
	 * @return array{GROUPS: list<array{ITEMS: list<array<string, mixed>>}>}
	 */
	public static function actionPanel(): array
	{
		$button = static fn(string $id, string $text, string $js): array => [
			'TYPE' => 'BUTTON', // Grid\Panel\Types::BUTTON
			'ID' => 'shef_aia_fb_'.$id,
			'CLASS' => '',
			'TEXT' => $text,
			// ACTION — Grid\Panel\Actions::CALLBACK: панель исполняет JS
			'ONCHANGE' => [['ACTION' => 'CALLBACK', 'DATA' => [['JS' => $js]]]],
		];

		return ['GROUPS' => [['ITEMS' => [
			$button('reviewed', 'Разобрано', "BX.Shef.ToolsAiAssistant.Feedback.status('".Review::REVIEWED."')"),
			$button('rejected', 'Отклонить', "BX.Shef.ToolsAiAssistant.Feedback.status('".Review::REJECTED."')"),
			$button('new', 'Вернуть в новые', "BX.Shef.ToolsAiAssistant.Feedback.status('".Review::NEW."')"),
			$button('export', 'Выгрузить как кейсы проверки', 'BX.Shef.ToolsAiAssistant.Feedback.export()'),
		]]]];
	}

	/** «general» — общий чат, «docs» — справка, «record:2:7» — запись CRM. */
	public static function chatTitle(string $chatKey): string
	{
		if(preg_match('/^record:(\d+):(\d+)$/', $chatKey, $m))
		{
			return 'запись CRM: тип '.$m[1].', № '.$m[2];
		}

		return $chatKey === 'docs' ? 'чат справки' : 'общий чат';
	}

	/**
	 * Адрес записи чата; только путь своего портала («/…», не «//…»).
	 *
	 * @param ?\Closure(int, int): ?string $recordUrl
	 */
	public static function recordUrl(string $chatKey, ?\Closure $recordUrl): ?string
	{
		$type = EvalCase::entityTypeId($chatKey);
		$id = EvalCase::recordId($chatKey);
		$url = $type !== null && $id !== null && $recordUrl !== null ? $recordUrl($type, $id) : null;

		return $url !== null && str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
	}

	/**
	 * Ячейка «Чат»: запись CRM — ссылкой, если известен адрес.
	 *
	 * @param ?\Closure(int, int): ?string $recordUrl
	 */
	public static function chatCell(string $chatKey, ?\Closure $recordUrl): string
	{
		$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$url = static::recordUrl($chatKey, $recordUrl);

		return $url !== null ? '<a href="'.$h($url).'">'.$h(static::chatTitle($chatKey)).'</a>' : $h(static::chatTitle($chatKey));
	}

	/** Кратко для ячейки: без разметки markdown, в одну строку, до PREVIEW_MAX знаков. */
	public static function preview(string $text): string
	{
		$text = (string)preg_replace('/```[a-z]*\n?|`|\*\*|__|^#{1,6}\s+|^\s*[-*]\s+/mu', '', $text);
		$text = (string)preg_replace('/\[([^\]]+)\]\([^)\s]+\)/u', '$1', $text);
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		return mb_strlen($text) > self::PREVIEW_MAX ? rtrim(mb_substr($text, 0, self::PREVIEW_MAX - 1)).'…' : $text;
	}

	/** JSON для вставки в <script>: <, >, &, кавычки — кодами. */
	public static function json(mixed $data): string
	{
		return (string)json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private static function date(mixed $value): string
	{
		return is_object($value) && method_exists($value, 'format') ? (string)$value->format('d.m.Y H:i') : (string)$value;
	}

	private static function ymd(string $date): string
	{
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

		return $parsed !== false ? $parsed->format('d.m.Y') : $date;
	}

	/**
	 * Адрес записи CRM — маршрутизатор CRM ядра (Crm\Service\Router::getItemDetailUrl,
	 * crm/lib/Service/Router.php); CRM нет — без ссылки.
	 *
	 * @return ?\Closure(int, int): ?string
	 */
	private static function recordUrlResolver(): ?\Closure
	{
		if(!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}
		$router = \Bitrix\Crm\Service\Container::getInstance()->getRouter();

		return static function(int $entityTypeId, int $id) use ($router): ?string
		{
			try
			{
				$url = $router->getItemDetailUrl($entityTypeId, $id);

				return $url !== null ? (string)$url : null;
			}
			catch(\Throwable)
			{
				return null;
			}
		};
	}

	/**
	 * Имена сотрудников одним запросом (UserTable ядра).
	 *
	 * @param list<int|string|null> $ids
	 * @return array<int, string>
	 */
	public static function userNames(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if($ids === [])
		{
			return [];
		}
		$names = [];
		$list = UserTable::getList(['select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'], 'filter' => ['@ID' => $ids]]);
		while($user = $list->fetch())
		{
			$name = trim($user['NAME'].' '.$user['LAST_NAME']);
			$names[(int)$user['ID']] = $name !== '' ? $name : (string)$user['LOGIN'];
		}

		return $names;
	}

	private static function alert(string $text): string
	{
		return '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</span></div>';
	}
}
