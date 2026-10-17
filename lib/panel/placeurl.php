<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Panel;

/**
 * Адрес места «Где показывать чат» и сопоставление его с текущей страницей
 * (1.3.0). Чистая логика без ядра (tests/places_test.php).
 *
 * Адрес места — путь своего сайта, при желании с параметрами запроса:
 * «/crm/», «/shef/toolsai/view/?type=2&category=0». Страница подходит, когда
 * её путь начинается с пути места и каждый параметр места есть у страницы с
 * тем же значением; лишние параметры страницы не мешают, порядок не важен.
 */
final class PlaceUrl
{
	/** Путь своего сайта и необязательный запрос: без схемы, домена, пробелов и «#». */
	private const PATTERN = '#^/(?!/)[\w\-./%~]*(\?[\w\-.%=&\[\]~+]*)?$#u';

	/**
	 * Привести адрес к виду для хранения: обрезать пробелы, отбросить пустой
	 * «?». Не путь своего сайта — null.
	 */
	public static function normalize(string $url): ?string
	{
		$url = rtrim(trim($url), '?&');
		if($url === '' || !preg_match(self::PATTERN, $url))
		{
			return null;
		}

		return $url;
	}

	/**
	 * Подходит ли страница месту.
	 *
	 * @param string $placeUrl адрес места (normalize())
	 * @param string $requestUri адрес страницы как в запросе: путь и запрос
	 */
	public static function matches(string $placeUrl, string $requestUri): bool
	{
		$placeUrl = static::normalize($placeUrl);
		if($placeUrl === null)
		{
			return false;
		}
		[$placePath, $placeQuery] = static::split($placeUrl);
		[$pagePath, $pageQuery] = static::split($requestUri);
		if(!str_starts_with($pagePath, $placePath))
		{
			return false;
		}

		$page = static::params($pageQuery);
		foreach(static::params($placeQuery) as $key => $value)
		{
			if(!array_key_exists($key, $page) || $page[$key] !== $value)
			{
				return false;
			}
		}

		return true;
	}

	/** @return array{0: string, 1: string} путь и запрос без «?» и «#…» */
	private static function split(string $uri): array
	{
		$uri = explode('#', $uri, 2)[0];
		$parts = explode('?', $uri, 2);

		return [$parts[0], $parts[1] ?? ''];
	}

	/**
	 * Параметры запроса как пары строк: ключ => значение. Массивы
	 * (a[]=1) сравниваются строкой целиком, как пришли.
	 *
	 * @return array<string, string>
	 */
	private static function params(string $query): array
	{
		$result = [];
		foreach(explode('&', $query) as $pair)
		{
			if($pair === '')
			{
				continue;
			}
			[$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
			$key = rawurldecode(str_replace('+', ' ', $key));
			if($key === '')
			{
				continue;
			}
			$result[$key] = rawurldecode(str_replace('+', ' ', $value));
		}

		return $result;
	}
}
