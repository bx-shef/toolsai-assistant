<?php declare(strict_types=1);

/**
 * Документация: относительные ссылки ведут на существующие файлы, а классы
 * модуля, названные в docs/guide и CLAUDE.md, существуют. Устаревшая ссылка
 * или переименованный класс в документе — то, что агент и человек находят
 * последними.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

$files = array_merge(
	glob($root.'/*.md') ?: [],
	glob($root.'/docs/*.md') ?: [],
	glob($root.'/docs/*/*.md') ?: [],
	glob($root.'/.claude/skills/README.md') ?: [],
);

Check::group('ссылки');

$broken = [];
foreach($files as $file)
{
	preg_match_all('/\]\(([^)#\s]+)(#[^)]*)?\)/u', (string)file_get_contents($file), $matches);
	foreach($matches[1] as $link)
	{
		if(preg_match('~^(https?:|mailto:)~', $link))
		{
			continue;
		}
		if(!file_exists(dirname($file).'/'.$link))
		{
			$broken[] = substr($file, strlen($root) + 1).' -> '.$link;
		}
	}
}
Check::same('документов нашлось', count($files) >= 12, true);
Check::same('относительные ссылки ведут на файлы', $broken, []);

Check::group('классы в документации');

$missing = [];
$checked = 0;
// 03-dependencies.md — про классы shef.toolsai, их здесь нет
foreach(array_merge(array_diff(glob($root.'/docs/guide/*.md') ?: [], [$root.'/docs/guide/03-dependencies.md']), [$root.'/CLAUDE.md']) as $file)
{
	preg_match_all('/`((?:Internals|Chat|Access|Public|Prompt|Panel|Main)(?:\\\\[A-Z][A-Za-z]+)+)(?:::|`)/u', (string)file_get_contents($file), $matches);
	foreach(array_unique($matches[1]) as $class)
	{
		$checked++;
		// По пути, как ищет ядро: подключать класс незачем (половина требует Битрикс)
		if(!is_file($root.'/lib/'.mb_strtolower(str_replace('\\', '/', $class)).'.php'))
		{
			$missing[] = basename($file).': '.$class;
		}
	}
}
Check::same('классы в документации нашлись', $checked >= 5, true);
Check::same('классы модуля из документации существуют', $missing, []);

Check::finish();
