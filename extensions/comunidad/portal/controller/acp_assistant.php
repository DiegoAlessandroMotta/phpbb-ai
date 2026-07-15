<?php
/**
 *
 * Portal Comunitario — ACP controller for the writing-assistant sub-page.
 *
 * Two responsibilities:
 *   - display_options()  — render the form (kill switch, rate limit,
 *                           input cap, max output tokens) plus the
 *                           read-only LLM status block (model + api
 *                           key, link to the extraction page where
 *                           those are actually configured)
 *   - save_options()     — persist the form to phpbb_config
 *
 * The LLM provider (key + model) is intentionally NOT editable here:
 * it is shared with the entity-extraction feature, and editing it
 * from two places would split the source of truth. The page shows
 * the current values read-only and links to the extraction page.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\controller;

use comunidad\portal\service\assistant\RateLimiter;
use comunidad\portal\service\llm\LlmClient;

class acp_assistant
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
	/** @var string */
	protected $u_action;
	/** @var string */
	protected $root_path;
	/** @var string */
	protected $php_ext;
	/** @var string */
	protected $table_prefix;
	/** @var LlmClient */
	protected $llm;
	/** @var RateLimiter */
	protected $rateLimiter;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\request\request $request,
		\phpbb\template\template $template,
		\phpbb\user $user,
		LlmClient $llm,
		RateLimiter $rateLimiter,
		$root_path,
		$php_ext,
		$table_prefix
	) {
		$this->config      = $config;
		$this->db          = $db;
		$this->request     = $request;
		$this->template    = $template;
		$this->user        = $user;
		$this->llm         = $llm;
		$this->rateLimiter = $rateLimiter;
		$this->root_path   = $root_path;
		$this->php_ext     = $php_ext;
		$this->table_prefix = $table_prefix;

		$this->user->add_lang_ext('comunidad/portal', 'acp');
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	public function display_options()
	{
		$apiKey = trim((string) ($this->config['portal_ai_gemini_api_key'] ?? ''));
		$model  = (string) ($this->config['portal_ai_gemini_model'] ?? 'gemini-3.1-flash-lite');

		$this->template->assign_vars([
			'U_ACTION'                              => $this->u_action,
			'S_ASSISTANT_ENABLED'                   => !empty($this->config['portal_assistant_enabled']),
			'S_LLM_CONFIGURED'                      => $this->llm->is_configured(),
			'S_LLM_KEY_SET'                         => $apiKey !== '',
			'PORTAL_AI_GEMINI_MODEL'                => $model,
			'PORTAL_ASSISTANT_MAX_PER_HOUR'         => (int) $this->config['portal_assistant_max_per_hour'],
			'PORTAL_ASSISTANT_INPUT_CAP'            => (int) $this->config['portal_assistant_input_cap'],
			'PORTAL_ASSISTANT_MAX_OUTPUT_TOKENS'    => (int) $this->config['portal_assistant_max_output_tokens'],
			'PORTAL_ASSISTANT_REQUESTS_24H'         => $this->countUsageSince(time() - 86400),
			'PORTAL_ASSISTANT_REQUESTS_7D'          => $this->countUsageSince(time() - 7 * 86400),
			'PORTAL_ASSISTANT_TOKENS_24H_PROMPT'    => $this->sumTokensSince(time() - 86400, 'prompt_tokens'),
			'PORTAL_ASSISTANT_TOKENS_24H_COMPLETION'=> $this->sumTokensSince(time() - 86400, 'completion_tokens'),
		]);
	}

	public function save_options()
	{
		$enabled        = $this->request->variable('portal_assistant_enabled', 0) ? 1 : 0;
		$maxPerHour     = max(1, min(1000, (int) $this->request->variable('portal_assistant_max_per_hour', 10)));
		$inputCap       = max(100, min(100000, (int) $this->request->variable('portal_assistant_input_cap', 8000)));
		$maxOutputTokens = max(50, min(8000, (int) $this->request->variable('portal_assistant_max_output_tokens', 900)));

		$this->config->set('portal_assistant_enabled', $enabled);
		$this->config->set('portal_assistant_max_per_hour', $maxPerHour);
		$this->config->set('portal_assistant_input_cap', $inputCap);
		$this->config->set('portal_assistant_max_output_tokens', $maxOutputTokens);

		trigger_error($this->user->lang['ACP_PORTAL_ASSISTANT_SAVED'] . adm_back_link($this->u_action));
	}

	private function countUsageSince(int $since): int
	{
		$sql = 'SELECT COUNT(*) AS c
			FROM ' . $this->table_prefix . 'portal_assistant_usage
			WHERE requested_at >= ' . $since;
		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return (int) ($row['c'] ?? 0);
	}

	private function sumTokensSince(int $since, string $column): int
	{
		$allowed = ['prompt_tokens', 'completion_tokens'];
		if (!in_array($column, $allowed, true)) {
			return 0;
		}
		$sql = 'SELECT COALESCE(SUM(' . $column . '), 0) AS s
			FROM ' . $this->table_prefix . 'portal_assistant_usage
			WHERE requested_at >= ' . $since;
		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return (int) ($row['s'] ?? 0);
	}
}
