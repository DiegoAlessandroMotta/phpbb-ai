<?php
/**
 *
 * Portal Comunitario — ACP module entry point.
 *
 * Wires the URL `adm/index.php?i=<id>&mode=news` to the
 * `comunidad.portal.acp.controller` service. Kept as a thin
 * shim so the module system has a single static entry to call;
 * all business logic lives in the controller.
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
