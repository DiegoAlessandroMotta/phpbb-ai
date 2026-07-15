<?php
/**
 *
 * Portal Comunitario — assistant service.
 *
 * Orchestrates a single assistant call:
 *   1. rate-limit gate (global, 1h rolling window)
 *   2. action + input validation (input cap, non-empty)
 *   3. LLM call via the LlmClient (generateText)
 *   4. usage row inserted in portal_assistant_usage
 *
 * The service throws RuntimeException with a human-readable Spanish
 * message on any user-facing failure (rate limit, invalid input, LLM
 * misconfig). The controller maps those to JsonResponse error bodies.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\assistant;

use comunidad\portal\service\llm\LlmClient;
use comunidad\portal\service\llm\LlmException;

class AssistantService
{
	private \phpbb\config\config $config;
	private \phpbb\db\driver\driver_interface $db;
	private LlmClient $llm;
	private RateLimiter $rateLimiter;
	private string $tablePrefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		LlmClient $llm,
		RateLimiter $rateLimiter,
		string $tablePrefix
	) {
		$this->config      = $config;
		$this->db          = $db;
		$this->llm         = $llm;
		$this->rateLimiter = $rateLimiter;
		$this->tablePrefix = $tablePrefix;
	}

	/**
	 * @return array{ok: bool, text?: string, error?: string, remaining?: int}
	 *
	 * @throws \RuntimeException on transport / provider errors (5xx-equivalent)
	 */
	public function run(string $action, string $text, int $userId): array
	{
		if (!ActionRegistry::isValid($action)) {
			return [
				'ok'    => false,
				'error' => 'Acción no reconocida.',
			];
		}

		$text = trim($text);
		if ($text === '') {
			return [
				'ok'    => false,
				'error' => 'El texto está vacío.',
			];
		}

		$cap = max(1, (int) $this->config['portal_assistant_input_cap']);
		if (mb_strlen($text) > $cap) {
			return [
				'ok'    => false,
				'error' => 'El texto supera el límite de ' . $cap . ' caracteres.',
			];
		}

		if (!$this->rateLimiter->canMakeRequest()) {
			return [
				'ok'        => false,
				'error'     => 'Has alcanzado el límite de uso del asistente por ahora. Intenta de nuevo en una hora.',
				'remaining' => 0,
			];
		}

		if (!$this->llm->is_configured()) {
			return [
				'ok'    => false,
				'error' => 'La IA no está configurada. Pide al administrador que defina la clave de API.',
			];
		}

		$systemPrompt = ActionRegistry::SYSTEM_PROMPT . "\n\n" . ActionRegistry::promptFor($action);
		$maxTokens    = max(50, (int) $this->config['portal_assistant_max_output_tokens']);

		try {
			$response = $this->llm->generateText($systemPrompt, $text, $maxTokens);
		}
		catch (LlmException $e)
		{
			throw new \RuntimeException('El servicio de IA falló: ' . $e->getMessage(), 0, $e);
		}

		$this->recordUsage($userId, $action, $response->promptTokens, $response->completionTokens);

		return [
			'ok'        => true,
			'text'      => trim($response->text),
			'remaining' => $this->rateLimiter->remainingInLastHour(),
		];
	}

	private function recordUsage(int $userId, string $action, int $promptTokens, int $completionTokens): void
	{
		$sql = 'INSERT INTO ' . $this->tablePrefix . 'portal_assistant_usage
				(user_id, action, requested_at, prompt_tokens, completion_tokens)
				VALUES (' . (int) $userId . ", '" . $this->db->sql_escape($action) . "', " . time() . ', '
				. (int) $promptTokens . ', ' . (int) $completionTokens . ')';
		$this->db->sql_query($sql);
	}
}
