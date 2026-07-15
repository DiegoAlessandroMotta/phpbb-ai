<?php
/**
 *
 * Portal Comunitario — assistant UI event listener.
 *
 * Hooks the two surfaces where the writing-assistant buttons render
 * (the posting editor and the PM view) and assigns the template vars
 * the inline JS / HTML need: the endpoint URL, the form token, the
 * quota badge, the LLM-configured gate.
 *
 * The template event files under styles/all/template/event/ are
 * picked up automatically by the template engine when posting.php /
 * ucp_pm_viewmessage.php render the editor / message HTML. The
 * listener only needs to populate the template before the render.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use comunidad\portal\service\assistant\RateLimiter;
use comunidad\portal\service\llm\LlmClient;

class assistant_listener implements EventSubscriberInterface
{
	/** @var \phpbb\config\config */
	private $config;
	/** @var \phpbb\template\template */
	private $template;
	/** @var \phpbb\user */
	private $user;
	/** @var \phpbb\controller\helper */
	private $helper;
	/** @var LlmClient */
	private $llm;
	/** @var RateLimiter */
	private $rateLimiter;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\controller\helper $helper,
		LlmClient $llm,
		RateLimiter $rateLimiter
	) {
		$this->config      = $config;
		$this->template    = $template;
		$this->user        = $user;
		$this->helper      = $helper;
		$this->llm         = $llm;
		$this->rateLimiter = $rateLimiter;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.posting_modify_default_variables' => 'populate_for_posting',
			'core.ucp_pm_view_message'              => 'populate_for_pm',
		];
	}

	public function populate_for_posting(): void
	{
		$this->user->add_lang_ext('comunidad/portal', 'common');
		$this->assignCommonVars();
	}

	public function populate_for_pm(): void
	{
		$this->user->add_lang_ext('comunidad/portal', 'common');
		$this->assignCommonVars();
	}

	private function assignCommonVars(): void
	{
		add_form_key('portal_assistant', '_PORTAL_ASSISTANT');

		$enabled  = !empty($this->config['portal_assistant_enabled']);
		$ready    = $this->llm->is_configured();
		$limit    = $this->rateLimiter->limitPerHour();
		$used     = $this->rateLimiter->usedInLastHour();
		$remaining = max(0, $limit - $used);
		$canRun   = $enabled && $ready && $remaining > 0;

		$this->template->assign_vars([
			'S_PORTAL_AI_READY'             => $ready,
			'S_PORTAL_ASSISTANT_ENABLED'    => $enabled,
			'S_PORTAL_ASSISTANT_CAN_RUN'    => $canRun,
			'PORTAL_ASSISTANT_REMAINING'    => $remaining,
			'PORTAL_ASSISTANT_LIMIT'        => $limit,
			'PORTAL_ASSISTANT_USED'         => $used,
			'U_PORTAL_ASSISTANT_ENDPOINT'   => $this->helper->route('comunidad_portal_assistant'),
		]);
	}
}
