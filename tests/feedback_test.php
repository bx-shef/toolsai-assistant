<?php declare(strict_types=1);

/**
 * Оценки ответов (1.1.0): разбор присланной оценки (Feedback\Rating),
 * вёрстка страницы «Оценки ответов» (экранирование всего, что пришло от
 * сотрудника и модели) и право feedback.view. С 1.5.0 — грид и фильтр,
 * сводка по ролям, статус и ожидаемое (Review), обезличивание (Anonymizer),
 * кейс проверки промптов (EvalCase), колонки разбора, право feedback.edit.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAiAssistant\Access\Actions;
use Shef\ToolsAiAssistant\Access\Rights;
use Shef\ToolsAiAssistant\Feedback\Anonymizer;
use Shef\ToolsAiAssistant\Feedback\EvalCase;
use Shef\ToolsAiAssistant\Feedback\FeedbackPage;
use Shef\ToolsAiAssistant\Feedback\GridFilter;
use Shef\ToolsAiAssistant\Feedback\RecordNames;
use Shef\ToolsAiAssistant\Feedback\Review;
use Shef\ToolsAiAssistant\Feedback\RoleSummary;
use Shef\ToolsAiAssistant\Main\TableInstaller;
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

Check::group('грид оценок');

$row = ['ID' => 1, 'CREATED_AT' => '2026-10-17 10:00', 'USER_ID' => 5, 'ROLE_CODE' => 'shef.toolsaiassistant:manager', 'CHAT_KEY' => 'record:2:7', 'QUESTION' => '<script>alert(1)</script>', 'ANSWER' => '**Жирно** <img src=x onerror=alert(1)>', 'RATING' => -1, 'COMMENT' => '"><b>', 'STATUS' => 'eval', 'EXPECTED' => "<i>факт</i>", 'REVIEWED_BY' => 9, 'REVIEWED_AT' => '2026-10-17 11:00'];
$url = static fn(int $type, int $id): ?string => '/crm/deal/details/'.$id.'/';
$grid = FeedbackPage::gridRows([$row], ['shef.toolsaiassistant:manager' => 'Помощник менеджера'], [5 => 'Иван <Петров>', 9 => 'Админ'], $url);
$cells = implode('', $grid[0]['columns']);
Check::same('вопрос, ответ, комментарий, ожидаемое, имя — экранированы', [str_contains($cells, '<script>'), str_contains($cells, '<img'), str_contains($cells, '"><b>'), str_contains($cells, '<i>'), str_contains($cells, 'Иван &lt;Петров&gt;')], [false, false, false, false, true]);
Check::same('ответ кратко — без звёздочек markdown', [str_contains($grid[0]['columns']['ANSWER'], '**'), str_contains($grid[0]['columns']['ANSWER'], 'Жирно')], [false, true]);
Check::same('роль — названием, чат — ссылкой на запись, оценка и статус — словом', [str_contains($cells, 'Помощник менеджера'), str_contains($grid[0]['columns']['CHAT'], '<a href="/crm/deal/details/7/">запись CRM: тип 2, № 7</a>'), str_contains($cells, 'Не помогло'), str_contains($cells, 'В наборе проверки')], [true, true, true, true]);
Check::same('кто разобрал', $grid[0]['columns']['REVIEWED'], 'Админ, 2026-10-17 11:00');
Check::same('id строки и действие «Открыть»', [$grid[0]['id'], $grid[0]['actions'][0]['onclick']], [1, 'BX.Shef.ToolsAiAssistant.Feedback.open(1)']);
Check::same('чужой адрес записи — без ссылки', [FeedbackPage::chatCell('record:2:7', static fn(): string => '//evil.example/'), FeedbackPage::chatCell('record:2:7', static fn(): string => 'javascript:alert(1)')], ['запись CRM: тип 2, № 7', 'запись CRM: тип 2, № 7']);
Check::same('общий чат и справка — без ссылки', [FeedbackPage::chatCell('general', $url), FeedbackPage::chatTitle('docs')], ['общий чат', 'чат справки']);
$old = FeedbackPage::gridRows([['STATUS' => null, 'EXPECTED' => null, 'REVIEWED_BY' => null] + $row], [], [], null);
Check::same('строка до 1.5.0: статус «Новая», ожидаемого нет', [str_contains($old[0]['columns']['STATUS'], 'Новая'), str_contains($old[0]['columns']['EXPECTED'], '—'), $old[0]['columns']['REVIEWED']], [true, true, '']);
Check::same('кратко: до PREVIEW_MAX, одной строкой, ссылка — текстом', [mb_strlen(FeedbackPage::preview(str_repeat('я ', 500))) <= FeedbackPage::PREVIEW_MAX, FeedbackPage::preview("# Итог\n- раз\n- [два](/crm/)"), FeedbackPage::preview('`код`')], [true, 'Итог раз два', 'код']);
$client = FeedbackPage::clientRows([$row], [], [5 => 'Иван'], $url);
Check::same('окно: текст целиком, адрес записи и промпта роли', [$client[1]['answer'], $client[1]['recordUrl'], $client[1]['promptUrl'], $client[1]['status']], [$row['ANSWER'], '/crm/deal/details/7/', '/shef/toolsai/?section=promptlib&tab=roles&code=shef.toolsaiassistant%3Amanager', 'eval']);
$json = FeedbackPage::json(['a' => '</script><b>&\'"']);
Check::same('JSON в <script>: теги, & и кавычки — кодами', [str_contains($json, '</script>'), str_contains($json, '<b>'), json_decode($json, true)['a']], [false, false, '</script><b>&\'"']);
Check::same('колонки: сортировка по дате, оценке, статусу; «Кто разобрал» — скрыта', [array_column(FeedbackPage::columns(), 'sort', 'id')['CREATED_AT'], array_column(FeedbackPage::columns(), 'default', 'id')['REVIEWED']], ['CREATED_AT', false]);
$panel = FeedbackPage::actionPanel()['GROUPS'][0]['ITEMS'];
Check::same('панель: Разобрано, Отклонить, Вернуть, Выгрузить — CALLBACK', [array_column($panel, 'TEXT'), $panel[3]['ONCHANGE'][0]['ACTION'], $panel[3]['ONCHANGE'][0]['DATA'][0]['JS']], [['Разобрано', 'Отклонить', 'Вернуть в новые', 'Выгрузить как кейсы проверки'], 'CALLBACK', 'BX.Shef.ToolsAiAssistant.Feedback.export()']);
Check::same('фильтр рейтинга: up, down, прочее — все', [FeedbackPage::ratingFilter('up'), FeedbackPage::ratingFilter('down'), FeedbackPage::ratingFilter('x')], [1, -1, null]);

Check::group('сводка по ролям');

$summary = RoleSummary::build([
	['ROLE_CODE' => 'a:good', 'RATING' => 1, 'CNT' => 9], ['ROLE_CODE' => 'a:good', 'RATING' => -1, 'CNT' => 1],
	['ROLE_CODE' => 'a:bad', 'RATING' => '1', 'CNT' => '2'], ['ROLE_CODE' => 'a:bad', 'RATING' => '-1', 'CNT' => '6'],
	['ROLE_CODE' => 'a:thin', 'RATING' => -1, 'CNT' => 1],
], ['a:good' => 'Хорошая <роль>']);
Check::same('сверху — худшая из надёжных, мало оценок — внизу', array_column($summary, 'code'), ['a:bad', 'a:good', 'a:thin']);
Check::same('доля и счёт', [$summary[0]['share'], $summary[0]['up'], $summary[0]['down'], $summary[1]['share'], $summary[2]['reliable']], [25.0, 2, 6, 90.0, false]);
Check::same('название роли; нет — код; ссылка на библиотеку промптов', [$summary[1]['title'], $summary[0]['title'], $summary[0]['url']], ['Хорошая <роль>', 'a:bad', '/shef/toolsai/?section=promptlib&tab=roles&code=a%3Abad']);
$html = FeedbackPage::summary($summary, ['from' => '2026-10-01', 'to' => '2026-10-17']);
Check::same('сводка: период, экранирование, «Править промпт роли», «мало оценок»', [str_contains($html, 'с 01.10.2026 по 17.10.2026'), str_contains($html, 'Хорошая &lt;роль&gt;'), substr_count($html, 'Править промпт роли'), str_contains($html, 'мало оценок')], [true, true, 3, true]);
Check::same('сводка пуста', str_contains(FeedbackPage::summary([], []), 'Оценок под фильтр нет'), true);

Check::group('фильтр грида');

$values = GridFilter::values(['PERIOD_from' => '01.10.2026', 'PERIOD_to' => '17.10.2026 00:00:00', 'RATING' => 'down', 'STATUS' => ['new', 'x'], 'ROLE' => ['shef.toolsaiassistant:manager', 'drop table'], 'USER' => 'U5', 'CHAT' => 'record:2', 'RECORD_ID_from' => '7']);
Check::same('значения фильтра — разбор, мусор выброшен', $values, ['from' => '2026-10-01', 'to' => '2026-10-17', 'rating' => -1, 'status' => ['new'], 'role' => ['shef.toolsaiassistant:manager'], 'user' => 5, 'chat' => 'record:2', 'recordId' => 7]);
Check::same('в ORM', GridFilter::orm($values), ['>=CREATED_AT' => '2026-10-01 00:00:00', '<=CREATED_AT' => '2026-10-17 23:59:59', '=RATING' => -1, '@STATUS' => ['new'], '@ROLE_CODE' => ['shef.toolsaiassistant:manager'], '=USER_ID' => 5, '%=CHAT_KEY' => 'record:2:7']);
Check::same('сводка — без оценки, роли, статуса', array_keys(GridFilter::orm($values, ['rating', 'role', 'status'])), ['>=CREATED_AT', '<=CREATED_AT', '=USER_ID', '%=CHAT_KEY']);
Check::same('чат: общий, любая запись, тип записи, номер без типа', [GridFilter::orm(['chat' => 'general']), GridFilter::orm(['chat' => 'record']), GridFilter::orm(['chat' => 'record:1']), GridFilter::orm(['recordId' => 7])], [['=CHAT_KEY' => 'general'], ['%=CHAT_KEY' => 'record:%'], ['%=CHAT_KEY' => 'record:1:%'], ['%=CHAT_KEY' => 'record:%:7']]);
Check::same('пустой фильтр', [GridFilter::values([]), GridFilter::orm([])], [[], []]);
Check::same('сортировка: своя колонка, чужая — от свежих', [GridFilter::sort(['RATING' => 'asc']), GridFilter::sort(['CREATED_AT' => 'desc']), GridFilter::sort(['QUESTION' => 'asc']), GridFilter::sort(['ROLE' => 'x'])], [['RATING' => 'ASC', 'ID' => 'DESC'], ['CREATED_AT' => 'DESC'], ['ID' => 'DESC'], ['ROLE_CODE' => 'DESC', 'ID' => 'DESC']]);
Check::same('поля фильтра: период, оценка, статус, роль, сотрудник, чат, номер записи', array_column(GridFilter::fields([]), 'id'), ['PERIOD', 'RATING', 'STATUS', 'ROLE', 'USER', 'CHAT', 'RECORD_ID']);

Check::group('разбор оценки: статус и ожидаемое');

Check::same('статус: известный или null', [Review::status('eval'), Review::status('drop'), Review::status(['new'])], ['eval', null, null]);
Check::same('подпись: неизвестный — «Новая»', [Review::title(null), Review::title('rejected')], ['Новая', 'Отклонена']);
Check::same('ожидаемое: подчищено', Review::expected("  раз \r\n\r\n\r\n\r\nдва\x07  "), "раз\n\nдва");
Check::same('ожидаемое: до EXPECTED_MAX', mb_strlen(Review::expected(str_repeat('я', 9000))), Review::EXPECTED_MAX);
Check::same('факты: строка — факт, | — варианты, ! — чего быть не должно, маркеры списка убраны', Review::facts("- нет следующего шага | шага нет\n\n2) созвониться до пятницы\n! скидка\n!  \n|"), ['facts' => [['нет следующего шага', 'шага нет'], ['созвониться до пятницы']], 'forbidden' => ['скидка']]);
Check::same('ID: целые > 0, без повторов', [Review::ids(['3', 3, '0', '-1', 'x', 7, ['8']]), Review::ids('5'), count(Review::ids(range(1, 500)))], [[3, 7], [5], Review::BATCH_MAX]);
Check::same('вручную «в наборе проверки» не ставится', in_array(Review::EVAL, Review::MANUAL, true), false);

Check::group('обезличивание');

Check::same('почта и телефон', Anonymizer::clean('Пишите ivan.petrov@mail.ru или +7 (912) 345-67-89, 8-912-345-67-89; сумма 150000 руб., 12.10.2026'), 'Пишите [почта] или [телефон], [телефон]; сумма 150000 руб., 12.10.2026');
Check::same('известное имя и его слова', Anonymizer::clean('Ответ для Иван Петров. Петров звонил, Иван — нет.', ['Иван Петров']), 'Ответ для [имя]. [имя] звонил, [имя] — нет.');
Check::same('«Имя Фамилия» после роли', Anonymizer::clean('Клиент Анна Сергеевна Котова просила счёт. Что мешает?'), 'Клиент [имя] просила счёт. Что мешает?');
Check::same('имя из двух слов в начале предложения', Anonymizer::clean('Котова Анна просила счёт. Звонил. Пётр Сидоров перезвонит.'), '[имя] просила счёт. Звонил. [имя] перезвонит.');
Check::same('роль и одно имя', Anonymizer::clean('Клиент Анна ждёт'), 'Клиент [имя] ждёт');
Check::same('обычное начало предложения и одиночные слова — как есть', Anonymizer::clean('Сделка Ромашка. Что Мешает закрыть?'), 'Сделка Ромашка. Что Мешает закрыть?');
Check::same('инициалы до и после фамилии', Anonymizer::clean('Договорился Петров И.И. и И. И. Сидоров'), 'Договорился [имя] и [имя]');
Check::same('инициалы за известной фамилией', Anonymizer::clean('Звонил Петров И.И.', ['Петров']), 'Звонил [имя]');
Check::same('имя заглавными; сокращения — как есть', Anonymizer::clean('Звонил ИВАН ПЕТРОВ из ООО, ИНН в CRM'), 'Звонил [имя] из ООО, ИНН в CRM');
Check::same('известная компания', Anonymizer::clean('счёт для ООО «Ромашка», ромашка ждёт', ['ООО «Ромашка»']), 'счёт для [имя], [имя] ждёт');
Check::same('почта нестандартно', Anonymizer::clean('ivan (at) mail (dot) ru и petr собака yandex.ru, у клиента собака лает'), '[почта] и [почта], у клиента собака лает');
Check::same('телефон от 7 цифр с разделителями; сумма — нет', Anonymizer::clean('Тел. 345-67-89, 123 45 67, (017) 2345678; сумма 1 500 000 и 1500000'), 'Тел. [телефон], [телефон], [телефон]; сумма 1 500 000 и 1500000');
Check::same('карта 16–19 цифр', Anonymizer::clean('Карта 4276 1234 5678 9012 и 4276123456789012345'), 'Карта [карта] и [карта]');
Check::same('паспорт, ИНН, СНИЛС', Anonymizer::clean('паспорт 4506 123456, ИНН: 7701234567, СНИЛС 123-456-789 01, КПП 770101001'), 'паспорт [документ], ИНН: [документ], СНИЛС [документ], КПП [документ]');
Check::same('СНИЛС по виду', Anonymizer::clean('номер 123-456-789 01'), 'номер [документ]');
Check::same('адрес по маркерам', Anonymizer::clean('Адрес: ул. Ленина, д. 5, кв. 7. Ещё д. 12, кв. 3, а дом клиента большой'), 'Адрес: [адрес]. Ещё [адрес], а дом клиента большой');
Check::same('проспект и офис', Anonymizer::clean('Офис: пр-т Независимости 12, оф. 301.'), 'Офис: [адрес].');
Check::same('короткие и пустые имена не трогают текст', Anonymizer::clean('Я из CRM', ['', 'Я', '#5']), 'Я из CRM');

Check::group('кейс проверки');

$review = ['ID' => 12, 'CREATED_AT' => new \DateTimeImmutable('2026-10-17 10:00'), 'USER_ID' => 5, 'ROLE_CODE' => 'shef.toolsaiassistant:manager', 'CHAT_KEY' => 'record:2:7', 'QUESTION' => 'Что мешает? Звонил Иван Петров, ivan@mail.ru', 'ANSWER' => 'ответ', 'RATING' => -1, 'COMMENT' => 'не увидел, что Иван не перезвонил', 'STATUS' => 'reviewed', 'EXPECTED' => "нет следующего шага | шага нет\n! скидка"];
$result = EvalCase::fromRows(
	[$review, ['ID' => 13, 'EXPECTED' => ''] + $review, ['ID' => 14, 'STATUS' => 'rejected'] + $review, ['ID' => 15, 'CHAT_KEY' => 'general', 'RATING' => 1, 'COMMENT' => null] + $review],
	[12 => ['title' => 'Сделка с ООО Ромашка, тел. 89123456789', 'text' => str_repeat('история ', 400)]],
	[12 => ['Иван Петров']]
);
Check::same('кейсы — только с ожидаемым и не отклонённые', [$result['ids'], $result['skipped']], [[12, 15], [13 => EvalCase::SKIP_NO_EXPECTED, 14 => EvalCase::SKIP_REJECTED]]);
$case = $result['cases'][0];
Check::same('формат кейса', array_keys($case), ['id', 'task', 'role', 'question', 'record', 'expect', 'note']);
Check::same('id, задача, роль', [$case['id'], $case['task'], $case['role']], ['assistant-feedback-12.chat', 'chat', 'shef.toolsaiassistant:manager']);
Check::same('вопрос обезличен', $case['question'], 'Что мешает? Звонил [имя], [почта]');
Check::same('ожидаемое — факты и запреты', $case['expect'], ['facts' => [['нет следующего шага', 'шага нет']], 'forbidden' => ['скидка']]);
Check::same('комментарий — в note, обезличен', $case['note'], 'Оценка «не помогло» №12, 17.10.2026. Комментарий: не увидел, что [имя] не перезвонил');
Check::same('запись: тип, заголовок обезличен, история кратко', [$case['record']['entityTypeId'], $case['record']['title'], mb_strlen($case['record']['text']) <= EvalCase::RECORD_MAX], [2, 'Сделка с ООО Ромашка, тел. [телефон]', true]);
Check::same('общий чат — без записи; запись не видна — пустой текст', [$result['cases'][1]['record'], EvalCase::fromRow($review, ['facts' => [['x']], 'forbidden' => []], null, [])['record']], [null, ['entityTypeId' => 2, 'title' => '', 'text' => '']]);
Check::same('note «полезно» без комментария', $result['cases'][1]['note'], 'Оценка «полезно» №15, 17.10.2026.');
Check::same('JSON: кириллица как есть, читаемый', [str_contains(EvalCase::json($result['cases']), 'нет следующего шага'), str_contains(EvalCase::json($result['cases']), "\n    ")], [true, true]);
Check::same('тип и номер записи из ключа чата', [EvalCase::entityTypeId('record:2:7'), EvalCase::recordId('record:2:7'), EvalCase::entityTypeId('general')], [2, 7, null]);

Check::group('имена из записи CRM');

Check::same('ФИО, компания лида, связанные; без пустых и повторов', RecordNames::fromFields(['TITLE' => 'Лид с сайта', 'NAME' => 'Анна', 'SECOND_NAME' => '', 'LAST_NAME' => 'Котова', 'COMPANY_TITLE' => 'ООО Ромашка'], ['ООО Ромашка', 'Пётр Сидоров', '', 'x']), ['Лид с сайта', 'Анна', 'Котова', 'ООО Ромашка', 'Анна Котова', 'Пётр Сидоров']);
Check::same('сделка: заголовок и связанные', RecordNames::fromFields(['TITLE' => 'Поставка', 'NAME' => null], ['ИП Иванов']), ['Поставка', 'ИП Иванов']);

Check::group('колонки разбора в старой таблице');

// FeedbackTable::REVIEW_COLUMNS — сущность ORM без ядра не грузится; список сверяем с исходником
$reviewColumns = ['STATUS', 'EXPECTED', 'REVIEWED_BY', 'REVIEWED_AT'];
Check::same('список колонок — как в FeedbackTable', str_contains((string)file_get_contents($root.'/lib/feedback/model/feedbacktable.php'), "REVIEW_COLUMNS = ['STATUS', 'EXPECTED', 'REVIEWED_BY', 'REVIEWED_AT']"), true);

Check::same('недостающие колонки без учёта регистра', TableInstaller::missingColumns(['ID', 'status', 'COMMENT'], $reviewColumns), ['EXPECTED', 'REVIEWED_BY', 'REVIEWED_AT']);
Check::same('все есть — ничего', TableInstaller::missingColumns($reviewColumns, $reviewColumns), []);
$esc = static fn(string $v): string => addslashes($v);
Check::same('часть ALTER: обязательная — NOT NULL DEFAULT, прочая — NULL', [TableInstaller::columnSql('`STATUS`', 'varchar(16)', true, 'new', $esc), TableInstaller::columnSql('`EXPECTED`', 'longtext', false, null, $esc), TableInstaller::columnSql('`N`', 'int', true, 0, $esc), TableInstaller::columnSql('`S`', 'varchar(5)', true, "a'b", $esc)], ["ADD COLUMN `STATUS` varchar(16) NOT NULL DEFAULT 'new'", 'ADD COLUMN `EXPECTED` longtext NULL', 'ADD COLUMN `N` int NOT NULL DEFAULT 0', "ADD COLUMN `S` varchar(5) NOT NULL DEFAULT 'a\\'b'"]);
$tableSource = (string)file_get_contents($root.'/lib/feedback/model/feedbacktable.php');
Check::same('индекс разбора — STATUS + CREATED_AT', str_contains($tableSource, "'columns' => ['STATUS', 'CREATED_AT']"), true);
$installerSource = (string)file_get_contents($root.'/lib/main/tableinstaller.php');
Check::same('ALTER — один, под блокировкой, с перечитыванием', [substr_count($installerSource, "queryExecute('ALTER TABLE"), str_contains($installerSource, '->lock($lock, 0)'), str_contains($installerSource, '->unlock($lock)')], [1, true, true]);
$storeSource = (string)file_get_contents($root.'/lib/feedback/feedbackstore.php');
Check::same('запись оценки не падает из-за ensure()', (bool)preg_match('/function add\(.*?try\s*\{\s*static::ensure\(\);\s*\}\s*catch\(\\\\Throwable/s', $storeSource), true);

Check::group('право feedback.view');

Check::same('администратору — всегда', Rights::resolve(true, [], [])->canViewFeedback(), true);
Check::same('чат не даёт смотреть оценки', Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::CHAT_USE]]])->canViewFeedback(), false);
Check::same('право роли доступа — даёт, чат не тянет', [Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_VIEW]]])->canViewFeedback(), Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_VIEW]]])->canUseChat()], [true, false]);
Check::same('право — в списке страницы прав', isset(Actions::getList()[Actions::FEEDBACK_VIEW]), true);

Check::group('право feedback.edit');

Check::same('администратору — всегда', Rights::resolve(true, [], [])->canEditFeedback(), true);
$editor = Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_EDIT]]]);
Check::same('разбирать — тянет смотреть, но не чат', [$editor->canEditFeedback(), $editor->canViewFeedback(), $editor->canUseChat()], [true, true, false]);
Check::same('смотреть не даёт разбирать', Rights::resolve(false, ['U1'], [['codes' => ['U1'], 'actions' => [Actions::FEEDBACK_VIEW]]])->canEditFeedback(), false);
Check::same('право — в списке страницы прав', isset(Actions::getList()[Actions::FEEDBACK_EDIT]), true);

Check::finish();
