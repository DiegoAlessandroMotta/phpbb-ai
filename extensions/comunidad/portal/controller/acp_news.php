<?php
/**
 *
 * Portal Comunitario — ACP controller for the news block.
 *
 * Two responsibilities:
 *   - display_options()  — render the form (forums dropdown + limit input)
 *   - save_options()     — persist the form to phpbb_config
 *
 * The module class (`acp/main_module.php`) is a thin shim that
 * instantiates this controller via the DI container and dispatches
 * to one of the two methods.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\controller;

class acp_news
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

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\request\request $request,
		\phpbb\template\template $template,
		\phpbb\user $user
	) {
		$this->config	= $config;
		$this->db		= $db;
		$this->request	= $request;
		$this->template	= $template;
		$this->user		= $user;

		$this->user->add_lang_ext('comunidad/portal', 'acp');
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	public function display_options()
	{
		$current = (int) $this->config['portal_news_forum_id'];

		$this->template->assign_block_vars('portal_news_forums', [
			'FORUM_ID'		=> 0,
			'FORUM_NAME'	=> $this->user->lang['PORTAL_NEWS_NO_FORUM'],
			'S_SELECTED'	=> ($current === 0),
		]);

		$sql = 'SELECT forum_id, forum_name
				FROM ' . FORUMS_TABLE . '
				WHERE forum_type = ' . FORUM_POST . '
				ORDER BY left_id ASC';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result)) {
			$fid = (int) $row['forum_id'];
			$this->template->assign_block_vars('portal_news_forums', [
				'FORUM_ID'		=> $fid,
				'FORUM_NAME'	=> $row['forum_name'],
				'S_SELECTED'	=> ($fid === $current),
			]);
		}
		$this->db->sql_freeresult($result);

		$this->template->assign_vars([
			'U_ACTION'			=> $this->u_action,
			'PORTAL_NEWS_LIMIT'	=> (int) $this->config['portal_news_limit'],
		]);
	}

	public function save_options()
	{
		$forum_id = $this->request->variable('portal_news_forum_id', 0);
		$limit	= $this->request->variable('portal_news_limit', 3);

		if ($limit < 1) {
			$limit = 1;
		} elseif ($limit > 10) {
			$limit = 10;
		}

		if ($forum_id > 0) {
			$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . (int) $forum_id;
			$result = $this->db->sql_query($sql);
			$exists = (bool) $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$exists) {
				$forum_id = 0;
			}
		}

		$this->config->set('portal_news_forum_id', $forum_id);
		$this->config->set('portal_news_limit', $limit);

		trigger_error($this->user->lang['PORTAL_NEWS_SAVED'] . adm_back_link($this->u_action));
	}
}
