<?php
/**
 *
 * Portal Comunitario — writing-assistant module (table + config + ACP sub-page).
 *
 * Adds the persistence and gates for the five user-facing AI assistant
 * actions (improve, friendly, summary, title, reply). This migration
 * intentionally does NOT add a controller or UI — those land in
 * separate commits so each commit is independently testable.
 *
 * Rate limit is GLOBAL (not per-user) for the PoC: a single
 * phpbb_config counter covers all users in the current hour window.
 * Per-user can be added later by keying on user_id; the schema
 * already records user_id on every row, so the promotion is a
 * code change, not a migration.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_3_0_assistant extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'portal_assistant_usage');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'portal_assistant_usage' => [
					'COLUMNS' => [
						'usage_id'         => ['UINT', null, 'auto_increment'],
						'user_id'          => ['UINT', 0],
						'action'           => ['VCHAR:32', ''],
						'requested_at'     => ['TIMESTAMP', 0],
						'prompt_tokens'    => ['UINT', 0],
						'completion_tokens'=> ['UINT', 0],
					],
					'PRIMARY_KEY' => 'usage_id',
					'KEYS' => [
						'i_requested_at' => ['INDEX', 'requested_at'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'portal_assistant_usage',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['portal_assistant_enabled', 1]],
			['config.add', ['portal_assistant_max_per_hour', 10]],
			['config.add', ['portal_assistant_input_cap', 8000]],
			['config.add', ['portal_assistant_max_output_tokens', 900]],

			['module.add', [
				'acp',
				'ACP_PORTAL_TITLE',
				[
					'module_basename' => '\comunidad\portal\acp\main_module',
					'module_langname' => 'ACP_PORTAL_ASSISTANT',
					'module_mode'     => 'assistant',
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
				'ACP_PORTAL_ASSISTANT',
			]],

			['config.remove', ['portal_assistant_enabled']],
			['config.remove', ['portal_assistant_max_per_hour']],
			['config.remove', ['portal_assistant_input_cap']],
			['config.remove', ['portal_assistant_max_output_tokens']],
		];
	}
}
