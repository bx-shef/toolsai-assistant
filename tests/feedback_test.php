<?php declare(strict_types=1);

/**
 * Оценки ответов (1.1.0): разбор присланной оценки (Feedback\Rating),
 * вёрстка страницы «Оценки ответов» (экранирование всего, что пришло от
 * сотрудника и модели) и право feedback.view.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Access\Actions;
use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Feedback\FeedbackPage;
use Shef\ToolsAiAssistant\Feedback\Rating;

Check::group('разбор оценки');

$good = ['rating' => 'up', 'role' => ' Shef.ToolsAiAssistant:manager ', 'record' => ['entityTypeId' => 2, 'id' => 7], 'question' => ' Что мешает? ', 'answer' => 'Нет шага', 'comment' => "ок\x07"];
Check::same('полезно — 1, роль строчными, чат записи, текст без управляющих', Rating::parse($good), ['rating' => 1, 'role' => 'shef.toolsaiassistant:manager', 'chatKey' => 'record:2:7', 'question' => 'Что мешает?', 'answer' => 'Нет шага', 'comment' => 'ок']);
Check::same('не помогло — -1, без записи — общий чат', [Rating::parse(['rating' => 'down', 'record' => []] + $good)['rating'], Rating::parse(['rating' => '-1', 'record' => null] + $good)['chatKey']], [-1, 'general']);
Check::same('другая оценка — мусор', [Rating::parse(['rating' => '5'] + $good), Rating::parse(['rating' => ''] + $good)], [null, null]);
Check::same('роль не код промпта — мусор', Rating::parse(['role' => 'drop table'] + $good), null);
Check::same('без вопроса или ответа — мусор', [Rating::parse(['question' => ' '] + $good), Rating::parse(['answer' => ['x']] + $good)], [null, null]);
$long = Rating::parse(['answer' => str_repeat('я', 5000)] + $good);
Check::same('ответ — кратко: до ANSWER_MAX знаков с «…»', [mb_strlen($long['answer']) <= Rating::ANSWER_MAX, str_ends_with($long['answer'], '…')], [true, true]);
Check::same('перевод строки остаётся', Rating::parse(['question' => "раз\nдва"] + $good)['question'], "раз\nдва");

Check::group('страница оценок');

$row = ['ID' => 1, 'CREATED_AT' => '2026-10-17 10:00', 'USER_ID' => 5, 'ROLE_CODE' => 'shef.toolsaiassistant:manager', 'CHAT_KEY' => 'record:2:7', 'QUESTION' => '<script>alert(1)</script>', 'ANSWER' => '<img src=x onerror=alert(1)>', 'RATING' => -1, 'COMMENT' => '"><b>'];
$html = FeedbackPage::render([$row], 1, 1, null, ['shef.toolsaiassistant:manager' => 'Помощник менеджера'], [5 => 'Иван <Петров>']);
Check::same('вопрос, ответ, комментарий, имя — экранированы', [str_contains($html, '<script>'), str_contains($html, '<img'), str_contains($html, '"><b>'), str_contains($html, 'Иван &lt;Петров&gt;')], [false, false, false, true]);
Check::same('роль — названием, чат — записью, оценка — словом', [str_contains($html, 'Помощник менеджера'), str_contains($html, 'запись CRM: тип 2, № 7'), str_contains($html, 'Не помогло')], [true, true, true]);
Check::same('пусто — подсказка', str_contains(FeedbackPage::render([], 0, 1, null, [], []), 'Оценок пока нет'), true);
$paged = FeedbackPage::render([$row], 120, 2, Rating::UP, [], []);
Check::same('страницы: обе стороны, фильтр в ссылках', [str_contains($paged, 'страница 2 из 3'), str_contains($paged, 'rating=up&amp;page=3'), str_contains($paged, '?rating=up"')], [true, true, true]);
Check::same('фильтр: up, down, прочее — все', [FeedbackPage::ratingFilter('up'), FeedbackPage::ratingFilter('down'), FeedbackPage::ratingFilter('x')], [1, -1, null]);
Check::same('общий чат', FeedbackPage::chatTitle('general'), 'общий чат');

Check::group('право feedback.view');

Check::same('администратору — всегда', Rights::resolve(true, [], [])->canViewFeedback(), true);
Check::same('чат не даёт смотреть оценки', Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::CHAT_USE]]])->canViewFeedback(), false);
Check::same('право роли доступа — даёт, чат не тянет', [Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_VIEW]]])->canViewFeedback(), Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_VIEW]]])->canUseChat()], [true, false]);
Check::same('право — в списке страницы прав', isset(Actions::getList()[Actions::FEEDBACK_VIEW]), true);

Check::finish();
