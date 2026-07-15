<?php
/**
 *
 * Portal Comunitario — add OpenAI as an alternate LLM provider.
 *
 * Keeps Gemini as the default so existing installs don't change
 * behaviour. Adding the OpenAI config keys (key + model) up front
 * means an admin can switch the provider by saving a new value in
 * the ACP without first running another migration.
 *
 * Idempotent: the `effectively_installed()` short-circuits when
 * the `portal_ai_provider` config row already exists.
 *
 * Switching the provider at runtime requires `phpbbcli.php
 * cache:purge` (or the equivalent UI action) so the Symfony
 * service container re-resolves the LlmClient.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\migrations;

class v1_3_1_ai_providers extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['portal_ai_provider']);
	}

	public function update_data()
	{
		return [
			['config.add', ['portal_ai_provider', 'gemini']],
			['config.add', ['portal_ai_openai_api_key', '']],
			['config.add', ['portal_ai_openai_model', 'gpt-4o-mini']],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['portal_ai_provider']],
			['config.remove', ['portal_ai_openai_api_key']],
			['config.remove', ['portal_ai_openai_model']],
		];
	}
}
