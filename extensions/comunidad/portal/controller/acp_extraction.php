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
		$apiKey = trim((string) ($this->config['portal_ai_gemini_api_key'] ?? ''));
		$model = (string) ($this->config['portal_ai_gemini_model'] ?? 'gemini-3.1-flash-lite');

		$this->template->assign_vars([
			'U_ACTION'                 => $this->u_action,
			'S_EXTRACTION_ENABLED'     => !empty($this->config['portal_extraction_enabled']),
			'S_LLM_CONFIGURED'         => $this->extraction->isConfigured(),
			'S_PORTAL_NEWS_LLM_KEY_SET'=> $apiKey !== '',
			'PORTAL_AI_GEMINI_MODEL'   => $model,
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
	 * Handle the config-form POST. Updates portal_ai_gemini_model
	 * and (only if non-empty) portal_ai_gemini_api_key. The key
	 * field is type=password, so the browser sends an empty value
	 * when the admin leaves it untouched — we treat that as "keep
	 * the current key" rather than clobbering it.
	 */
	public function save_options()
	{
		$apiKey = trim((string) $this->request->variable('portal_ai_gemini_api_key', '', true));
		$model = trim((string) $this->request->variable('portal_ai_gemini_model', '', true));

		if ($model === '') {
			$model = 'gemini-3.1-flash-lite';
		}
		$this->config->set('portal_ai_gemini_model', $model);

		// Only update the key if the admin actually typed something.
		// An empty POST value means "leave the current key alone".
		if ($apiKey !== '') {
			$this->config->set('portal_ai_gemini_api_key', $apiKey);
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
