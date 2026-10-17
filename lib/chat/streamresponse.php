<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Chat;

use Bitrix\Main\HttpResponse;

/**
 * Ответ действия потоком (text/event-stream).
 *
 * В ядре потокового ответа контроллера нет (main/lib/engine/response: Json,
 * File, Zip\Archive…), но HttpApplication::runController() отдаёт как есть
 * любой наследник HttpResponse, который вернуло действие, и зовёт его send()
 * (main/lib/httpapplication.php buildResponse(), application.php end()). Так
 * сделаны Engine\Response\File и Zip\Archive: свой send(). Здесь send()
 * пишет заголовки и печатает события по мере прихода: $producer получает
 * $emit(string) и зовёт его на каждый кусок.
 *
 * Буферы PHP сбрасываются, сжатие отключается: иначе ответ пришёл бы целиком в
 * конце. Закрытая вкладка ответ не обрывает (ignore_user_abort): журнал
 * расхода shef.toolsai должен дописаться. Время скрипта — TIME_LIMIT: ответ
 * модели бывает дольше max_execution_time портала, а таймаут запроса к модели
 * держит провайдер shef.toolsai.
 *
 * «: ping» — только в начале: пока модель молчит, PHP ждёт внутри
 * LlmService::stream() (curl), и вставить что-то в поток из этого места
 * нельзя. Поэтому таймаут чтения прокси (nginx proxy_read_timeout /
 * fastcgi_read_timeout) должен быть больше таймаута модели —
 * docs/guide/03-dependencies.md.
 */
final class StreamResponse extends HttpResponse
{
	/** Секунд на весь ответ (set_time_limit). */
	public const TIME_LIMIT = 300;

	/** @param \Closure(callable(string): void): void $producer */
	public function __construct(private readonly \Closure $producer)
	{
		parent::__construct();
		foreach(StreamEvents::HEADERS as $name => $value)
		{
			$this->addHeader($name, $value);
		}
	}

	/** Отправить заголовки и печатать события потока по мере прихода. */
	public function send()
	{
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}
		ignore_user_abort(true);
		@set_time_limit(self::TIME_LIMIT);
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		$this->writeHeaders();
		$emit = static function(string $chunk): void
		{
			echo $chunk;
			flush();
		};
		$emit(StreamEvents::ping());
		($this->producer)($emit);
	}
}
