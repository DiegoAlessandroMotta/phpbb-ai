<?php
/**
 *
 * Portal Comunitario — news meta + extraction log tables.
 *
 * Sets up:
 *   - portal_news_meta             (1 row per topic, holds extraction result)
 *   - portal_news_extraction_log   (append-only audit trail of attempts)
 *   - config keys for AI provider, batch size, and cadence
 *
 * Required by feature 3 (entity extraction). No behavior change
 * until a follow-up commit wires the event listener and cron
 * task that actually call the LlmClient.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_2_0_news_meta extends \phpbb\db\migration\migration
{
	public function update_schema()
	{
		return [
			'add_tables' => [
				'portal_news_meta' => [
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
				'portal_news_extraction_log' => [
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

	public function update_data()
	{
		return [
			['config.add', ['portal_ai_gemini_api_key', '']],
			['config.add', ['portal_ai_gemini_model', 'gemini-3.1-flash-lite']],
			['config.add', ['portal_ai_max_output_tokens', 1000]],
			['config.add', ['portal_extraction_enabled', 1]],
			['config.add', ['portal_extraction_batch_size', 20]],
			['config.add', ['portal_extraction_max_attempts', 3]],
			['config.add', ['portal_extraction_last_run', 0]],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				'portal_news_meta',
				'portal_news_extraction_log',
			],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['portal_ai_gemini_api_key']],
			['config.remove', ['portal_ai_gemini_model']],
			['config.remove', ['portal_ai_max_output_tokens']],
			['config.remove', ['portal_extraction_enabled']],
			['config.remove', ['portal_extraction_batch_size']],
			['config.remove', ['portal_extraction_max_attempts']],
			['config.remove', ['portal_extraction_last_run']],
		];
	}
}
