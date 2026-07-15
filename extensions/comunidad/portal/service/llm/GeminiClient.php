<?php
/**
 *
 * Portal Comunitario — Gemini LLM client.
 *
 * Talks to the Google Gemini generateContent endpoint using cURL.
 * Reads the API key and model from phpbb_config (settable from
 * ACP), with the GEMINI_API_KEY env var as a fallback for dev.
 *
 * Uses Gemini's `responseSchema` + `responseMimeType: application/json`
 * to force structured output, then parses the returned text into
 * the LlmResponse::$parsed array.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

class GeminiClient implements LlmClient
{
	private string $apiKey;
	private string $model;
	private string $endpoint;

	public function __construct(\phpbb\config\config $config)
	{
		$configKey = isset($config['portal_ai_gemini_api_key'])
			? trim((string) $config['portal_ai_gemini_api_key'])
			: '';
		$envKey = getenv('GEMINI_API_KEY') ?: '';

		$this->apiKey = $configKey !== '' ? $configKey : $envKey;

		$configModel = isset($config['portal_ai_gemini_model'])
			? trim((string) $config['portal_ai_gemini_model'])
			: '';
		$this->model = $configModel !== '' ? $configModel : 'gemini-3.1-flash-lite';

		$this->endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. $this->model . ':generateContent';
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
			throw new LlmException('Gemini API key not configured. Set portal_ai_gemini_api_key in phpbb_config or the GEMINI_API_KEY env var.');
		}

		$body = [
			'contents' => [
				['role' => 'user', 'parts' => [['text' => $userText]]],
			],
			'systemInstruction' => [
				'parts' => [['text' => $systemPrompt]],
			],
			'generationConfig' => [
				'responseMimeType' => 'application/json',
				'responseSchema'   => $jsonSchema,
				'maxOutputTokens'  => $maxOutputTokens,
				'temperature'      => 0.0,
			],
		];

		$ch = curl_init($this->endpoint);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'x-goog-api-key: ' . $this->apiKey,
			],
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_CONNECTTIMEOUT => 5,
		]);

		$response = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			throw new LlmException('Gemini transport error: ' . $error, 0);
		}
		if ($httpCode !== 200) {
			throw new LlmException(
				'Gemini returned HTTP ' . $httpCode . ': ' . substr($response, 0, 500),
				$httpCode
			);
		}

		$decoded = json_decode($response, true);
		if (!is_array($decoded)
			|| empty($decoded['candidates'][0]['content']['parts'][0]['text'])
		) {
			throw new LlmException('Gemini response malformed: ' . substr($response, 0, 500), 200);
		}

		$text = (string) $decoded['candidates'][0]['content']['parts'][0]['text'];
		$parsed = json_decode($text, true);
		if (!is_array($parsed)) {
			throw new LlmException(
				'Gemini returned non-JSON despite responseSchema: ' . substr($text, 0, 500),
				200
			);
		}

		return new LlmResponse(
			text:             $text,
			parsed:           $parsed,
			promptTokens:     (int) ($decoded['usageMetadata']['promptTokenCount']     ?? 0),
			completionTokens: (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0),
			model:            (string) ($decoded['modelVersion'] ?? $this->model),
		);
	}
}
