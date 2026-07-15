<?php
/**
 *
 * Portal Comunitario — ACP module entry point.
 *
 * Wires ACP module URLs to their respective controllers based on
 * the `mode` parameter. Kept as a thin shim so the module system
 * has a single static entry per category; all business logic
 * lives in the controllers.
 *
 * Modes:
 *   - news       (default) — portal news block config
 *   - extraction           — entity extraction list + re-extraer
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\acp;

class main_module
{
	/** @var string */
	public $u_action;

	/** @var string */
	public $tpl_name;

	/** @var string */
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $user;

		if ($mode === 'extraction') {
			$this->tpl_name    = 'acp_extraction';
			$this->page_title  = 'ACP_PORTAL_EXTRACTION';

			$controller = $phpbb_container->get('comunidad.portal.acp.extraction.controller');
			$controller->set_page_url($this->u_action);

			if ($request->is_set_post('reextract')) {
				$controller->reextract($request->variable('topic_id', 0));
			}
			else if ($request->is_set_post('save')) {
				$controller->save_options();
			}

			$controller->display();
			return;
		}

		// Default: news block config.
		$this->tpl_name		= 'acp_news';
		$this->page_title	= 'ACP_PORTAL_NEWS';

		$controller = $phpbb_container->get('comunidad.portal.acp.controller');
		$controller->set_page_url($this->u_action);

		if ($request->is_set_post('submit')) {
			$controller->save_options();
		}

		$controller->display_options();
	}
}
