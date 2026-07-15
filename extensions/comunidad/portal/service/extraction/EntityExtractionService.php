<?php
/**
 *
 * Portal Comunitario — entity extraction business logic.
 *
 * Single public method: extractForTopic(int). Loads the first post
 * of a topic, cleans the text (strip BBCode, decode entities,
 * collapse whitespace), calls the LlmClient, then upserts the
 * result into portal_news_meta and appends a row to
 * portal_news_extraction_log.
 *
 * Does NOT manage retries. The cron task owns the retry state
 * machine (attempt_count, next_attempt_at) — this service only
 * writes the outcome of a single attempt.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\extraction;

use comunidad\portal\service\llm\LlmClient;
use comunidad\portal\service\llm\LlmException;

class EntityExtractionService
{
	public const STATUS_PENDING  = 0;
	public const STATUS_OK       = 1;
	public const STATUS_FAILED   = 2;
	public const STATUS_PARTIAL  = 3;

	/** @var \phpbb\config\config */
	private $config;
	/** @var \phpbb\db\driver\driver_interface */
	private $db;
	/** @var LlmClient */
	private $llmClient;
	/** @var string */
	private $tablePrefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		LlmClient $llmClient,
		string $tablePrefix
	) {
		$this->config = $config;
		$this->db = $db;
		$this->llmClient = $llmClient;
		$this->tablePrefix = $tablePrefix;
	}

	/**
	 * True if the underlying LLM provider has what it needs to
	 * make a call (API key set, etc).
	 */
	public function isConfigured(): bool
	{
		return $this->llmClient->is_configured();
	}

	/**
	 * Run entity extraction for a single topic and persist the
	 * outcome. Throws on hard transport errors so the cron can
	 * decide whether to retry.
	 *
	 * @param int $topicId The topic to extract from.
	 *
	 * @throws \RuntimeException If the topic/first post is missing
	 *                           or the post text is empty.
	 * @throws LlmException      If the LLM call fails.
	 */
	public function extractForTopic(int $topicId): void
	{
		$topicId = (int) $topicId;
		if ($topicId <= 0) {
			throw new \RuntimeException('Invalid topic_id: ' . $topicId);
		}

		$post = $this->loadFirstPost($topicId);
		$cleanText = $this->cleanPostText($post['post_text'], $post['bbcode_uid']);

		if ($cleanText === '') {
			throw new \RuntimeException('Post text is empty after cleaning.');
		}

		$prompt = require __DIR__ . '/../llm/prompts/entity_extraction.php';
		$system = $prompt['system'] . "\n\nTítulo de la noticia: " . $post['topic_title'];
		$maxTokens = (int) ($this->config['portal_ai_max_output_tokens'] ?? 1000);
		if ($maxTokens < 100) {
			$maxTokens = 100;
		}

		$status = self::STATUS_FAILED;
		$entities = null;
		$error = null;
		$model = '';
		$promptTokens = 0;
		$completionTokens = 0;
		$rawResponse = '';

		try {
			$response = $this->llmClient->extract($system, $cleanText, $prompt['schema'], $maxTokens);
			$rawResponse = $response->text;
			$entities = $response->parsed;
			$model = $response->model;
			$promptTokens = $response->promptTokens;
			$completionTokens = $response->completionTokens;
			$status = $this->validateEntities($entities) ? self::STATUS_OK : self::STATUS_PARTIAL;
		}
		catch (LlmException $e)
		{
			$error = $e->getMessage();
		}
		catch (\Throwable $e)
		{
			$error = $e->getMessage();
		}

		$this->upsertMeta($topicId, $status, $entities, $model, $promptTokens, $completionTokens, $error);
		$this->appendLog($topicId, $status, $error, $rawResponse);
	}

	private function loadFirstPost(int $topicId): array
	{
		$sql = 'SELECT p.post_text, p.bbcode_uid, t.topic_title
				FROM ' . TOPICS_TABLE . ' t
				JOIN ' . POSTS_TABLE . ' p ON p.post_id = t.topic_first_post_id
				WHERE t.topic_id = ' . $topicId;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row) {
			throw new \RuntimeException('Topic or first post not found: ' . $topicId);
		}

		return [
			'post_text'   => (string) $row['post_text'],
			'bbcode_uid'  => (string) $row['bbcode_uid'],
			'topic_title' => (string) $row['topic_title'],
		];
	}

	private function cleanPostText(string $text, string $bbcodeUid): string
	{
		if ($bbcodeUid !== '') {
			strip_bbcode($text, $bbcodeUid);
		}
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text);
		return trim($text);
	}

	/**
	 * Check that the parsed response has all five top-level keys
	 * and each is an array. Otherwise mark as PARTIAL — JSON
	 * was valid but the shape drifted.
	 */
	private function validateEntities(array $entities): bool
	{
		$required = ['people', 'organizations', 'places', 'dates', 'sources'];
		foreach ($required as $key) {
			if (!array_key_exists($key, $entities) || !is_array($entities[$key])) {
				return false;
			}
		}
		return true;
	}

	private function upsertMeta(
		int $topicId,
		int $status,
		?array $entities,
		string $model,
		int $promptTokens,
		int $completionTokens,
		?string $error
	): void {
		$extractedAt = ($status === self::STATUS_OK || $status === self::STATUS_PARTIAL) ? time() : 0;
		$entitiesJson = $entities !== null ? json_encode($entities, JSON_UNESCAPED_UNICODE) : '';
		if ($entitiesJson === false) {
			$entitiesJson = '';
		}

		$insertRow = [
			'topic_id'          => $topicId,
			'extraction_status' => $status,
			'extracted_at'      => $extractedAt,
			'next_attempt_at'   => 0,
			'attempt_count'     => 0,
			'entities_json'     => $entitiesJson,
			'model_name'        => $model,
			'prompt_tokens'     => $promptTokens,
			'completion_tokens' => $completionTokens,
			'error_message'     => $error ?? '',
		];

		$sql = 'INSERT INTO ' . $this->tablePrefix . 'portal_news_meta '
			. $this->db->sql_build_array('INSERT', $insertRow)
			. ' ON DUPLICATE KEY UPDATE
				extraction_status = VALUES(extraction_status),
				extracted_at = VALUES(extracted_at),
				entities_json = VALUES(entities_json),
				model_name = VALUES(model_name),
				prompt_tokens = VALUES(prompt_tokens),
				completion_tokens = VALUES(completion_tokens),
				error_message = VALUES(error_message)';
		$this->db->sql_query($sql);
	}

	private function appendLog(int $topicId, int $status, ?string $error, string $rawResponse): void
	{
		$row = [
			'topic_id'      => $topicId,
			'attempted_at'  => time(),
			'status'        => $status,
			'error_message' => $error ?? '',
			'request_hash'  => '',
			'response_raw'  => mb_substr($rawResponse, 0, 100000),
		];
		$sql = 'INSERT INTO ' . $this->tablePrefix . 'portal_news_extraction_log '
			. $this->db->sql_build_array('INSERT', $row);
		$this->db->sql_query($sql);
	}
}
