<?php
/**
 *
 * Portal Comunitario — Migration: news block.
 *
 * Adds:
 *   - portal_news_forum_id  (int, default 0 = disabled)
 *   - portal_news_limit     (int, default 3)
 *   - ACP category "Portal Comunitario" under Extensions tab
 *   - ACP sub-module "News" inside the category
 *
 * Run automatically by phpBB on extension enable (after the
 * v1_0_0 base migration, or as the first migration if v1_0_0
 * is not present).
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_1_0_add_news_block extends \phpbb\db\migration\container_aware_migration
{
	public function effectively_installed()
	{
		return isset($this->config['portal_news_forum_id']);
	}

	public function update_data()
	{
		return [
			['config.add', ['portal_news_forum_id', 0]],
			['config.add', ['portal_news_limit', 3]],

			['module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_PORTAL_TITLE',
			]],
			['module.add', [
				'acp',
				'ACP_PORTAL_TITLE',
				[
					'module_basename'	=> '\comunidad\portal\acp\main_module',
					'module_langname'	=> 'ACP_PORTAL_NEWS',
					'module_mode'		=> 'news',
					'module_auth'		=> 'ext_comunidad/portal && acl_a_board',
				],
			]],
		];
	}

	public function revert_data()
	{
		return [
			['module.remove', [
				'acp',
				'ACP_PORTAL_TITLE',
				[
					'module_basename'	=> '\comunidad\portal\acp\main_module',
					'module_langname'	=> 'ACP_PORTAL_NEWS',
					'module_mode'		=> 'news',
				],
			]],
			['module.remove', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_PORTAL_TITLE']],

			['config.remove', ['portal_news_forum_id']],
			['config.remove', ['portal_news_limit']],
		];
	}
}
