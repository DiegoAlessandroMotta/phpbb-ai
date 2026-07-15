<?php
/**
 *
 * Portal Comunitario — initial migration.
 *
 * Sets up:
 *   - portal_news_forum_id  (int, default 0 = disabled)
 *   - portal_news_limit     (int, default 3)
 *   - ACP category "Portal Comunitario" under the Extensions tab
 *   - ACP sub-module "News" inside that category
 *
 * This is the only migration. There is no prior version to migrate
 * from; the news block is part of the initial state of the extension.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_1_0_initial extends \phpbb\db\migration\container_aware_migration
{
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
}
