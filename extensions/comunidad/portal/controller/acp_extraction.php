<?php
/**
 *
 * Portal Comunitario — ACP controller for the entity-extraction page.
 *
 * Renders a table of recent extractions (status, attempt count,
 * tokens, model, error message) with a per-row "Re-extraer" form
 * that resets the row to PENDING and runs the extraction
 * synchronously.
 *
 * Lives under the existing `ACP_PORTAL_TITLE` category as a
 * separate module (mode = "extraction"). See
 * migrations/v1_2_1_extraction_module.php for the registration.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\controller;

use comunidad\portal\service\extraction\EntityExtractionService;
use comunidad\portal\service\llm\LlmClientFactory;

class acp_extraction
{
	/** @var \phpbb\config\config */
	protected $config;
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;
	/** @var \phpbb\request\request */
	protected $request;
	/** @var \phpbb\template\template */
	protected $template;
	/** @var \phpbb\user */
	protected $user;
	/** @var EntityExtractionService */
	protected $extraction;
	/** @var \phpbb\cache\service\cache */
	protected $cache;
	/** @var string */
	protected $u_action;
	/** @var string */
	protected $root_path;
	/** @var string */
	protected $php_ext;
	/** @var string */
	protected $table_prefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\request\request $request,
		\phpbb\template\template $template,
		\phpbb\user $user,
		EntityExtractionService $extraction,
		\phpbb\cache\service\cache $cache,
		$root_path,
		$php_ext,
		$table_prefix
	) {
		$this->config = $config;
		$this->db = $db;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->extraction = $extraction;
		$this->cache = $cache;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->table_prefix = $table_prefix;

		$this->user->add_lang_ext('comunidad/portal', 'acp');
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	/**
	 * Render the list page.
	 */
	public function display()
	{
		$lastRun = (int) ($this->config['portal_extraction_last_run'] ?? 0);
		$geminiKey = trim((string) ($this->config['portal_ai_gemini_api_key'] ?? ''));
		$geminiModel = (string) ($this->config['portal_ai_gemini_model'] ?? 'gemini-3.1-flash-lite');
		$openaiKey = trim((string) ($this->config['portal_ai_openai_api_key'] ?? ''));
		$openaiModel = (string) ($this->config['portal_ai_openai_model'] ?? 'gpt-4o-mini');
		$provider = (string) ($this->config['portal_ai_provider'] ?? LlmClientFactory::PROVIDER_GEMINI);

		$this->template->assign_vars([
			'U_ACTION'                 => $this->u_action,
			'S_EXTRACTION_ENABLED'     => !empty($this->config['portal_extraction_enabled']),
			'S_LLM_CONFIGURED'         => $this->extraction->isConfigured(),
			'S_PORTAL_NEWS_LLM_KEY_SET'=> $geminiKey !== '',
			'S_OPENAI_KEY_SET'         => $openaiKey !== '',
			'PORTAL_AI_GEMINI_MODEL'   => $geminiModel,
			'PORTAL_AI_OPENAI_MODEL'   => $openaiModel,
			'PORTAL_AI_PROVIDER'       => $provider,
			'S_PROVIDER_GEMINI'        => $provider === LlmClientFactory::PROVIDER_GEMINI,
			'S_PROVIDER_OPENAI'        => $provider === LlmClientFactory::PROVIDER_OPENAI,
			'LAST_RUN_FORMATTED'       => $lastRun > 0 ? $this->user->format_date($lastRun) : '',
		]);

		foreach ($this->load_recent_extractions(50) as $row) {
			$status = (int) $row['extraction_status'];
			$statusKey = 'PORTAL_EXTRACTION_STATUS_' . $status;

			$this->template->assign_block_vars('extractions', [
				'TOPIC_ID'          => (int) $row['topic_id'],
				'TOPIC_TITLE'       => (string) $row['topic_title'],
				'U_TOPIC'           => append_sid(
					"{$this->root_path}viewtopic.{$this->php_ext}",
					'f=' . (int) ($row['forum_id'] ?? 0) . '&amp;t=' . (int) $row['topic_id']
				),
				'STATUS'            => $status,
				'STATUS_LABEL'      => isset($this->user->lang[$statusKey])
					? $this->user->lang[$statusKey]
					: '#' . $status,
				'EXTRACTED_AT'      => $row['extracted_at'] > 0
					? $this->user->format_date($row['extracted_at'])
					: '',
				'ATTEMPT_COUNT'     => (int) $row['attempt_count'],
				'ERROR_MESSAGE'     => (string) ($row['error_message'] ?? ''),
				'MODEL_NAME'        => (string) ($row['model_name'] ?? ''),
				'PROMPT_TOKENS'     => (int) ($row['prompt_tokens'] ?? 0),
				'COMPLETION_TOKENS' => (int) ($row['completion_tokens'] ?? 0),
				'S_HAS_ERROR'       => !empty($row['error_message']),
			]);
		}
	}

	/**
	 * Handle the config-form POST. Updates:
	 *   - portal_ai_provider (gemini | openai)
	 *   - portal_ai_gemini_api_key (only if non-empty — see below)
	 *   - portal_ai_gemini_model
	 *   - portal_ai_openai_api_key (only if non-empty)
	 *   - portal_ai_openai_model
	 *
	 * Key fields are type=password, so the browser sends an empty
	 * value when the admin leaves the field untouched. We treat
	 * that as "keep the current key" rather than clobbering it.
	 *
	 * If the provider changed, the Symfony service container is
	 * purged so the new LlmClient takes effect on the next request
	 * (otherwise the cached factory result would still point at the
	 * old provider).
	 */
	public function save_options()
	{
		$provider = $this->request->variable('portal_ai_provider', LlmClientFactory::PROVIDER_GEMINI);
		if (!in_array($provider, LlmClientFactory::supportedProviders(), true)) {
			$provider = LlmClientFactory::PROVIDER_GEMINI;
		}
		$providerChanged = (string) ($this->config['portal_ai_provider'] ?? '') !== $provider;

		$geminiKey   = trim((string) $this->request->variable('portal_ai_gemini_api_key', '', true));
		$geminiModel = trim((string) $this->request->variable('portal_ai_gemini_model', '', true));
		if ($geminiModel === '') {
			$geminiModel = 'gemini-3.1-flash-lite';
		}

		$openaiKey   = trim((string) $this->request->variable('portal_ai_openai_api_key', '', true));
		$openaiModel = trim((string) $this->request->variable('portal_ai_openai_model', '', true));
		if ($openaiModel === '') {
			$openaiModel = 'gpt-4o-mini';
		}

		$this->config->set('portal_ai_provider', $provider);
		$this->config->set('portal_ai_gemini_model', $geminiModel);
		$this->config->set('portal_ai_openai_model', $openaiModel);
		if ($geminiKey !== '') {
			$this->config->set('portal_ai_gemini_api_key', $geminiKey);
		}
		if ($openaiKey !== '') {
			$this->config->set('portal_ai_openai_api_key', $openaiKey);
		}

		if ($providerChanged) {
			$this->cache->purge();
		}

		trigger_error(
			$this->user->lang['ACP_PORTAL_EXTRACTION_SAVED'] . adm_back_link($this->u_action),
			E_USER_NOTICE
		);
	}

	/**
	 * Handle a "Re-extraer" POST. Resets the meta row to PENDING
	 * (clears prior result, error, and attempt count) and runs
	 * the extraction synchronously, so the admin sees the outcome
	 * before being sent back to the list.
	 *
	 * @param int $topicId
	 */
	public function reextract($topicId)
	{
		$topicId = (int) $topicId;
		if ($topicId <= 0) {
			trigger_error(
				$this->user->lang['ACP_PORTAL_EXTRACTION_INVALID_TOPIC'] . adm_back_link($this->u_action),
				E_USER_WARNING
			);
		}

		$sql = 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topicId;
		$result = $this->db->sql_query($sql);
		$exists = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists) {
			trigger_error(
				$this->user->lang['ACP_PORTAL_EXTRACTION_INVALID_TOPIC'] . adm_back_link($this->u_action),
				E_USER_WARNING
			);
		}

		$now = time();
		$sql = 'INSERT INTO ' . $this->table_prefix . 'portal_news_meta
				(topic_id, extraction_status, extracted_at, next_attempt_at, attempt_count, entities_json, model_name, prompt_tokens, completion_tokens, error_message)
				VALUES (' . $topicId . ', 0, 0, ' . $now . ', 0, "", "", 0, 0, "")
				ON DUPLICATE KEY UPDATE
					extraction_status  = 0,
					extracted_at       = 0,
					next_attempt_at    = ' . $now . ',
					attempt_count      = 0,
					entities_json      = "",
					model_name         = "",
					prompt_tokens      = 0,
					completion_tokens  = 0,
					error_message      = ""';
		$this->db->sql_query($sql);

		try {
			$this->extraction->extractForTopic($topicId);
			trigger_error(
				$this->user->lang['ACP_PORTAL_EXTRACTION_SUCCESS'] . adm_back_link($this->u_action),
				E_USER_NOTICE
			);
		}
		catch (\Throwable $e)
		{
			$msg = $this->user->lang['ACP_PORTAL_EXTRACTION_FAILED']
				. '<br><br><strong>Error:</strong> <code>'
				. htmlspecialchars($e->getMessage(), ENT_QUOTES)
				. '</code>';
			trigger_error($msg . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function load_recent_extractions(int $limit): array
	{
		$sql = 'SELECT m.topic_id, m.extraction_status, m.extracted_at, m.attempt_count,
					   m.error_message, m.model_name, m.prompt_tokens, m.completion_tokens,
					   t.topic_title, t.forum_id
				FROM ' . $this->table_prefix . 'portal_news_meta m
				JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = m.topic_id
				ORDER BY m.extracted_at DESC, m.meta_id DESC';
		$result = $this->db->sql_query_limit($sql, $limit);

		$rows = [];
		while ($row = $this->db->sql_fetchrow($result)) {
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}
}
