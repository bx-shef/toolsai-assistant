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
 * расхода shef.toolsai должен дописаться.
 */
final class StreamResponse extends HttpResponse
{
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
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		$this->writeHeaders();
		($this->producer)(static function(string $chunk): void
		{
			echo $chunk;
			flush();
		});
	}
}
