<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Feedback;

use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use Shef\ToolsAiAssistant\Access\UserAccess;
use Shef\ToolsAiAssistant\Chat\Gateway\ToolsAiPromptSource;
use Shef\ToolsAiAssistant\Container;

/**
 * Страница «Оценки ответов» (1.1.0): /shef/toolsaiassistant/feedback/.
 *
 * Список от свежих, по PAGE_SIZE на странице, фильтр «все / полезно / не
 * помогло». Право — feedback.view (администратору портала — всегда).
 * Вёрстка — render(), чистая: всё, что пришло от сотрудников и модели,
 * экранируется (feedback_test.php).
 */
final class FeedbackPage
{
	public const URL = \Shef\ToolsAiAssistant\Main\Constants::FEEDBACK_URL;
	public const PAGE_SIZE = 50;

	/** Вывести страницу для текущего пользователя. */
	public static function show(): void
	{
		if(!UserAccess::current()->canViewFeedback())
		{
			echo static::alert('Нет доступа к оценкам ответов — право «Смотреть оценки ответов» выдаёт администратор портала на странице прав модуля.');

			return;
		}
		try
		{
			$request = Application::getInstance()->getContext()->getRequest();
			$rating = static::ratingFilter((string)$request->getQuery('rating'));
			$page = max(1, (int)$request->getQuery('page'));
			$filter = $rating !== null ? ['rating' => $rating] : [];
			$store = new FeedbackStore();
			$total = $store->count($filter);
			$rows = $store->list(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $filter);

			$roles = [];
			foreach((new ToolsAiPromptSource())->roles(null) as $role)
			{
				$roles[$role['code']] = $role['title'];
			}
			echo static::render($rows, $total, $page, $rating, $roles, static::userNames(array_column($rows, 'USER_ID')));
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(self::class)?->error($throwable);
			echo static::alert('Не удалось прочитать оценки — подробности в журнале модуля.');
		}
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

	/**
	 * Вёрстка списка.
	 *
	 * @param list<array<string, mixed>> $rows строки FeedbackStore::list()
	 * @param array<string, string> $roles код роли => название
	 * @param array<int, string> $users ID сотрудника => имя
	 */
	public static function render(array $rows, int $total, int $page, ?int $rating, array $roles, array $users): string
	{
		$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$current = $rating === Rating::UP ? 'up' : ($rating === Rating::DOWN ? 'down' : '');
		$link = static fn(string $filter, int $p = 1): string => self::URL.'?'.http_build_query(array_filter(['rating' => $filter, 'page' => $p > 1 ? $p : null]));

		$out = '<div class="shef-aia-feedback">';
		$out .= '<p>';
		foreach(['' => 'Все', 'up' => 'Полезно', 'down' => 'Не помогло'] as $code => $title)
		{
			$out .= $code === $current
				? '<b>'.$h($title).'</b> '
				: '<a href="'.$h($link($code)).'">'.$h($title).'</a> ';
		}
		$out .= '· всего: '.$total.'</p>';
		$out .= '<p style="color:#828b95">Вопрос и ответ присылает окно чата вместе с оценкой — это текст глазами сотрудника, а не запись сервера. В них бывают фрагменты записей CRM. Оценки старше срока из настроек удаляются автоматически.</p>';

		if($rows === [])
		{
			return $out.'<p>Оценок пока нет. Сотрудники ставят их кнопками «Полезно» и «Не помогло» под ответом помощника.</p></div>';
		}

		$out .= '<table class="shef-aia-feedback-table" style="width:100%;border-collapse:collapse">';
		$out .= '<tr><th align="left">Когда</th><th align="left">Кто</th><th align="left">Роль</th><th align="left">Чат</th><th align="left">Оценка</th><th align="left">Вопрос</th><th align="left">Ответ</th><th align="left">Комментарий</th></tr>';
		foreach($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$role = (string)$row['ROLE_CODE'];
			$created = $row['CREATED_AT'];
			$out .= '<tr style="vertical-align:top;border-top:1px solid #e0e4e8">'
				.'<td>'.$h(is_object($created) && method_exists($created, 'format') ? $created->format('d.m.Y H:i') : $created).'</td>'
				.'<td>'.$h($users[$userId] ?? ('#'.$userId)).'</td>'
				.'<td>'.$h($roles[$role] ?? $role).'</td>'
				.'<td>'.$h(static::chatTitle((string)$row['CHAT_KEY'])).'</td>'
				.'<td>'.((int)$row['RATING'] === Rating::UP ? '👍 ' : '👎 ').$h(Rating::title((int)$row['RATING'])).'</td>'
				.'<td>'.nl2br($h($row['QUESTION'])).'</td>'
				.'<td>'.nl2br($h($row['ANSWER'])).'</td>'
				.'<td>'.nl2br($h($row['COMMENT'] ?? '')).'</td>'
				.'</tr>';
		}
		$out .= '</table>';

		$pages = max(1, (int)ceil($total / self::PAGE_SIZE));
		if($pages > 1)
		{
			$out .= '<p>';
			if($page > 1)
			{
				$out .= '<a href="'.$h($link($current, $page - 1)).'">← новее</a> ';
			}
			$out .= 'страница '.$page.' из '.$pages;
			if($page < $pages)
			{
				$out .= ' <a href="'.$h($link($current, $page + 1)).'">старее →</a>';
			}
			$out .= '</p>';
		}

		return $out.'</div>';
	}

	/** «general» — общий чат, «record:2:7» — запись CRM. */
	public static function chatTitle(string $chatKey): string
	{
		return preg_match('/^record:(\d+):(\d+)$/', $chatKey, $m) ? 'запись CRM: тип '.$m[1].', № '.$m[2] : 'общий чат';
	}

	/**
	 * Имена сотрудников страницы одним запросом (UserTable ядра).
	 *
	 * @param list<int|string> $ids
	 * @return array<int, string>
	 */
	private static function userNames(array $ids): array
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
