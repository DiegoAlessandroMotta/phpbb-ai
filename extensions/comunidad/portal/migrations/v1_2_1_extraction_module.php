<?php
/**
 *
 * Portal Comunitario — register the Entity Extraction ACP module.
 *
 * Adds a new "Extracción de entidades" sub-module under the existing
 * "Portal Comunitario" ACP category, with mode = "extraction" handled
 * by the same `\comunidad\portal\acp\main_module` shim that already
 * serves the News page.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_2_1_extraction_module extends \phpbb\db\migration\migration
{
	public function update_data()
	{
		return [
			['module.add', [
				'acp',
				'ACP_PORTAL_TITLE',
				[
					'module_basename' => '\comunidad\portal\acp\main_module',
					'module_langname' => 'ACP_PORTAL_EXTRACTION',
					'module_mode'     => 'extraction',
					'module_auth'     => 'ext_comunidad/portal && acl_a_board',
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
				'ACP_PORTAL_EXTRACTION',
			]],
		];
	}
}
