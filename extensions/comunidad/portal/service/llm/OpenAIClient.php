<?php
/**
 *
 * Portal Comunitario — OpenAI LLM client.
 *
 * Talks to OpenAI's chat completions endpoint using cURL. Reads the
 * API key and model from phpbb_config (settable from ACP). Uses
 * Structured Outputs (response_format json_schema) for the extract()
 * path so the model is forced to return JSON matching the caller's
 * schema; the generateText() path leaves response_format unset so
 * the model returns free-form text.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

class OpenAIClient implements LlmClient
{
	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	private string $apiKey;
	private string $model;
	private string $endpoint;

	public function __construct(\phpbb\config\config $config)
	{
		$apiKey = isset($config['portal_ai_openai_api_key'])
			? trim((string) $config['portal_ai_openai_api_key'])
			: '';
		$envKey = getenv('OPENAI_API_KEY') ?: '';
		$this->apiKey = $apiKey !== '' ? $apiKey : $envKey;

		$configModel = isset($config['portal_ai_openai_model'])
			? trim((string) $config['portal_ai_openai_model'])
			: '';
		$this->model = $configModel !== '' ? $configModel : 'gpt-5.4-mini';

		$this->endpoint = self::ENDPOINT;
	}

	public function is_configured(): bool
	{
		return $this->apiKey !== '';
	}

	public function extract(
		string $systemPrompt,
		string $userText,
		array $jsonSchema,
		int $maxOutputTokens = 1000
	): LlmResponse {
		if ($this->apiKey === '') {
			throw new LlmException('OpenAI API key not configured. Set portal_ai_openai_api_key in phpbb_config or the OPENAI_API_KEY env var.');
		}

		$body = [
			'model'    => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user',   'content' => $userText],
			],
			'response_format' => [
				'type'       => 'json_schema',
				'json_schema' => [
					'name'   => 'response',
					'strict' => true,
					'schema' => $jsonSchema,
				],
			],
			'max_completion_tokens' => $maxOutputTokens,
			'temperature'           => 0.0,
		];

		$decoded = $this->call($body);

		$text = (string) ($decoded['choices'][0]['message']['content'] ?? '');
		if ($text === '') {
			throw new LlmException('OpenAI response malformed: ' . substr(json_encode($decoded), 0, 500), 200);
		}

		$parsed = json_decode($text, true);
		if (!is_array($parsed)) {
			throw new LlmException(
				'OpenAI returned non-JSON despite json_schema: ' . substr($text, 0, 500),
				200
			);
		}

		return new LlmResponse(
			text:             $text,
			parsed:           $parsed,
			promptTokens:     (int) ($decoded['usage']['prompt_tokens']     ?? 0),
			completionTokens: (int) ($decoded['usage']['completion_tokens'] ?? 0),
			model:            (string) ($decoded['model'] ?? $this->model),
		);
	}

	public function generateText(
		string $systemPrompt,
		string $userText,
		int $maxOutputTokens = 900
	): LlmResponse {
		if ($this->apiKey === '') {
			throw new LlmException('OpenAI API key not configured. Set portal_ai_openai_api_key in phpbb_config or the OPENAI_API_KEY env var.');
		}

		$body = [
			'model'    => $this->model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user',   'content' => $userText],
			],
			'max_completion_tokens' => $maxOutputTokens,
			'temperature'           => 0.4,
		];

		$decoded = $this->call($body);

		$text = (string) ($decoded['choices'][0]['message']['content'] ?? '');
		if ($text === '') {
			throw new LlmException('OpenAI response malformed: ' . substr(json_encode($decoded), 0, 500), 200);
		}

		return new LlmResponse(
			text:             $text,
			parsed:           ['text' => $text],
			promptTokens:     (int) ($decoded['usage']['prompt_tokens']     ?? 0),
			completionTokens: (int) ($decoded['usage']['completion_tokens'] ?? 0),
			model:            (string) ($decoded['model'] ?? $this->model),
		);
	}

	private function call(array $body): array
	{
		$ch = curl_init($this->endpoint);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->apiKey,
			],
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 5,
		]);

		$response = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error    = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			throw new LlmException('OpenAI transport error: ' . $error, 0);
		}
		if ($httpCode !== 200) {
			throw new LlmException(
				'OpenAI returned HTTP ' . $httpCode . ': ' . substr($response, 0, 500),
				$httpCode
			);
		}

		$decoded = json_decode($response, true);
		if (!is_array($decoded)) {
			throw new LlmException('OpenAI response not JSON: ' . substr($response, 0, 500), $httpCode);
		}
		return $decoded;
	}
}
