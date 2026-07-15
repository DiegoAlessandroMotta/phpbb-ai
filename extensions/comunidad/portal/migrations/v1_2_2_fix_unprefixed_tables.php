<?php
/**
 *
 * Portal Comunitario — fix the unprefixed tables from v1.2.0.
 *
 * The original v1.2.0 migration forgot to prepend $this->table_prefix
 * to the table names in its add_tables array, so the tables were
 * created as `portal_news_meta` and `portal_news_extraction_log`
 * instead of `phpbb_portal_news_meta` / `phpbb_portal_news_extraction_log`.
 *
 * This migration fixes the existing DB by:
 *   1. Dropping the unprefixed tables if they exist (no-op on fresh
 *      installs where the fixed v1.2.0 already used the prefix).
 *   2. Creating the correctly-prefixed tables.
 *
 * Idempotent: `effectively_installed()` short-circuits when the
 * prefixed `portal_news_meta` already exists (the case on this
 * dev DB after the manual fix, or on a fresh install that ran
 * the corrected v1.2.0).
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_2_2_fix_unprefixed_tables extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'portal_news_meta');
	}

	public function update_schema()
	{
		return [
			'drop_tables' => [
				'portal_news_meta',
				'portal_news_extraction_log',
			],
			'add_tables' => [
				$this->table_prefix . 'portal_news_meta' => [
					'COLUMNS' => [
						'meta_id'           => ['UINT', null, 'auto_increment'],
						'topic_id'          => ['UINT', 0],
						'extraction_status' => ['TINT:1', 0],
						'extracted_at'      => ['TIMESTAMP', 0],
						'next_attempt_at'   => ['TIMESTAMP', 0],
						'attempt_count'     => ['UINT', 0],
						'entities_json'     => ['MTEXT_UNI', ''],
						'model_name'        => ['VCHAR:64', ''],
						'prompt_tokens'     => ['UINT', 0],
						'completion_tokens' => ['UINT', 0],
						'error_message'     => ['TEXT_UNI', ''],
					],
					'PRIMARY_KEY' => 'meta_id',
					'KEYS' => [
						'topic_id'      => ['UNIQUE', 'topic_id'],
						'i_status_next' => ['INDEX', ['extraction_status', 'next_attempt_at']],
					],
				],
				$this->table_prefix . 'portal_news_extraction_log' => [
					'COLUMNS' => [
						'log_id'        => ['UINT', null, 'auto_increment'],
						'topic_id'      => ['UINT', 0],
						'attempted_at'  => ['TIMESTAMP', 0],
						'status'        => ['TINT:1', 0],
						'error_message' => ['TEXT_UNI', ''],
						'request_hash'  => ['VCHAR:64', ''],
						'response_raw'  => ['MTEXT_UNI', ''],
					],
					'PRIMARY_KEY' => 'log_id',
					'KEYS' => [
						'i_topic_id'     => ['INDEX', 'topic_id'],
						'i_attempted_at' => ['INDEX', 'attempted_at'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'portal_news_meta',
				$this->table_prefix . 'portal_news_extraction_log',
			],
		];
	}
}
