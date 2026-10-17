<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Mcp;

/**
 * Шифрование токенов MCP сотрудника перед записью в базу (1.9.0):
 * AES-256-GCM, ключ модуля (опция SYS_tokenkey, создаётся при первом
 * подключении). Ключ лежит в той же базе — это защита от утечки выгрузки
 * таблицы или резервной копии без опций, а не от администратора сервера.
 * Не расшифровалось (сменили ключ, испорчено) — null: подключиться заново.
 */
final class TokenCrypto
{
	private const PREFIX = 'v1:';

	public function __construct(private readonly string $key)
	{
	}

	/** Новый ключ: 32 случайных байта base64. */
	public static function newKey(): string
	{
		return base64_encode(random_bytes(32));
	}

	public function encrypt(string $plain): string
	{
		$iv = random_bytes(12);
		$tag = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->rawKey(), OPENSSL_RAW_DATA, $iv, $tag);

		return self::PREFIX.base64_encode($iv.$tag.(string)$cipher);
	}

	public function decrypt(string $value): ?string
	{
		if(!str_starts_with($value, self::PREFIX))
		{
			return null;
		}
		$raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
		if(!is_string($raw) || strlen($raw) < 29)
		{
			return null;
		}
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->rawKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

		return is_string($plain) ? $plain : null;
	}

	private function rawKey(): string
	{
		$raw = base64_decode($this->key, true);

		return is_string($raw) && strlen($raw) === 32 ? $raw : hash('sha256', $this->key, true);
	}
}
