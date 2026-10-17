<?php declare(strict_types=1);

/**
 * Общий журнал отзывов shef.toolsaieval (1.11.0): оценка → отзыв (EvalBridge),
 * куда пишется оценка (FeedbackRouter), перенос старых оценок пачками
 * (EvalMigration::step), подсказка на странице, вид отзыва для события.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Chat\Gateway\EvalGatewayInterface;
use Shef\ToolsAiAssistant\Chat\Gateway\FeedbackStoreInterface;
use Shef\ToolsAiAssistant\Feedback\EvalBridge;
use Shef\ToolsAiAssistant\Feedback\EvalMigration;
use Shef\ToolsAiAssistant\Feedback\FeedbackPage;
use Shef\ToolsAiAssistant\Feedback\FeedbackRouter;

final class FakeEval implements EvalGatewayInterface
{
	public bool $available = true;
	/** @var int|null|\Throwable что вернуть из record() */
	public mixed $recordResult = 77;
	/** @var array<int, int|null|\Throwable> номер источника => что вернуть из import() */
	public array $importResults = [];
	public array $records = [];
	public array $imports = [];

	public function isAvailable(): bool
	{
		return $this->available;
	}

	public function record(array $input): ?int
	{
		$this->records[] = $input;
		if($this->recordResult instanceof \Throwable)
		{
			throw $this->recordResult;
		}

		return $this->recordResult;
	}

	public function import(array $input, mixed $createdAt, int $sourceId, array $review): ?int
	{
		$this->imports[] = [$sourceId, $input['kind'], $review];
		$result = array_key_exists($sourceId, $this->importResults) ? $this->importResults[$sourceId] : 100 + $sourceId;
		if($result instanceof \Throwable)
		{
			throw $result;
		}

		return $result;
	}
}

final class FakeLocal implements FeedbackStoreInterface
{
	public array $added = [];
	public array $stamps = [];

	public function add(int $userId, array $rating): int
	{
		$this->added[] = $rating;

		return 5;
	}

	public function stamps(int $userId): array
	{
		return $this->stamps;
	}

	public function putStamps(int $userId, array $stamps): void
	{
		$this->stamps = $stamps;
	}
}

$rating = ['rating' => -1, 'role' => 'shef.toolsaiassistant:manager', 'chatKey' => 'record:2:7', 'question' => 'Что мешает?', 'answer' => 'Нет шага', 'comment' => 'ок'];

Check::group('оценка → отзыв');

Check::same('поля Entry', EvalBridge::entry(9, $rating), ['module' => 'shef.toolsaiassistant', 'kind' => 'chat', 'rating' => -1, 'userId' => 9, 'subject' => 'record:2:7', 'role' => 'shef.toolsaiassistant:manager', 'question' => 'Что мешает?', 'answer' => 'Нет шага', 'comment' => 'ок']);
$row = ['ID' => 3, 'CREATED_AT' => '2026-10-01 10:00:00', 'USER_ID' => '9', 'ROLE_CODE' => 'a:b', 'CHAT_KEY' => 'general', 'QUESTION' => 'В', 'ANSWER' => 'О', 'RATING' => '1', 'COMMENT' => null, 'STATUS' => 'eval', 'EXPECTED' => "факт\n! нет", 'REVIEWED_BY' => '4', 'REVIEWED_AT' => '2026-10-02'];
$data = EvalBridge::fromRow($row);
Check::same('строка таблицы: отзыв и разбор', [$data['entry']['userId'], $data['entry']['rating'], $data['entry']['comment'], $data['review']], [9, 1, '', ['status' => 'eval', 'expected' => "факт\n! нет", 'reviewedBy' => 4, 'reviewedAt' => '2026-10-02']]);
Check::same('строка до 1.5.0: статус «новая», без разбора', EvalBridge::fromRow(['STATUS' => null, 'EXPECTED' => null, 'REVIEWED_BY' => null, 'REVIEWED_AT' => null] + $row)['review'], ['status' => 'new']);
Check::same('вид для события: chat', EvalBridge::kinds(), [['code' => 'chat', 'title' => 'Ответ чата помощника', 'task' => 'chat']]);

Check::group('куда пишется оценка');

$eval = new FakeEval();
$local = new FakeLocal();
$router = new FeedbackRouter($local, $eval);
Check::same('есть shef.toolsaieval — туда, своя таблица пуста', [$router->add(9, $rating), count($eval->records), $local->added], [77, 1, []]);
$eval->recordResult = null;
Check::same('не записал — в свою таблицу', [$router->add(9, $rating), count($local->added)], [5, 1]);
$eval->recordResult = new \RuntimeException('сбой');
Check::same('сбой — в свою таблицу', [$router->add(9, $rating), count($local->added)], [5, 2]);
$eval->available = false;
$eval->records = [];
Check::same('модуля нет — сразу своя таблица', [$router->add(9, $rating), $eval->records], [5, []]);
$router->putStamps(9, [1, 2]);
Check::same('отметки частоты — свои', $router->stamps(9), [1, 2]);

Check::group('перенос пачкой');

$eval = new FakeEval();
$eval->importResults = [11 => 0, 12 => null];
$rows = [['ID' => 10] + $row, ['ID' => 11] + $row, ['ID' => 12] + $row, ['ID' => 13] + $row];
Check::same('перенесено, уже было, негодная — дальше; последний ID', EvalMigration::step($rows, 9, $eval), ['lastId' => 13, 'moved' => 2, 'failed' => [12]]);
Check::same('номер источника и вид — в import()', array_column($eval->imports, 0), [10, 11, 12, 13]);
$eval = new FakeEval();
$eval->importResults = [12 => new \RuntimeException('база')];
Check::same('сбой базы — стоп на этой строке', EvalMigration::step($rows, 9, $eval), ['lastId' => 11, 'moved' => 2, 'failed' => []]);
Check::same('пустая пачка — без изменений', EvalMigration::step([], 9, new FakeEval()), ['lastId' => 9, 'moved' => 0, 'failed' => []]);

Check::group('подсказка на странице оценок');

Check::same('перенесено — ссылка и «перенесены»', [str_contains(FeedbackPage::noticeHtml('/shef/toolsaieval/feedback/', 0), 'href="/shef/toolsaieval/feedback/"'), str_contains(FeedbackPage::noticeHtml('/x/', 0), 'перенесены')], [true, true]);
Check::same('осталось и сбой', [str_contains(FeedbackPage::noticeHtml('/x/', 12), 'осталось: 12'), str_contains(FeedbackPage::noticeHtml('/x/', -1), 'не удался')], [true, true]);
Check::same('чужой адрес — без ссылки', str_contains(FeedbackPage::noticeHtml('//evil.example/', 0), '<a '), false);

Check::group('подписка');

$settings = require $root.'/.settings.php';
$events = array_column(array_column($settings['installEvents']['value'], 'from'), 'event', 'event');
Check::same('событие видов shef.toolsaieval — в installEvents', isset($events['OnFeedbackKindsCollect']), true);
Check::same('обработчик существует', method_exists('Shef\\ToolsAiAssistant\\Internals\\Integration\\ToolsAiEval\\EventHandler\\OnFeedbackKindsCollectEventHandler', 'handle'), true);

Check::finish();
