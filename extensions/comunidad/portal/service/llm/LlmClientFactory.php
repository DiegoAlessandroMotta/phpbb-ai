<?php
/**
 *
 * Portal Comunitario — LlmClient factory.
 *
 * Resolves the active provider from phpbb_config and returns the
 * concrete LlmClient implementation. The provider is selected via
 * the `portal_ai_provider` config key (values: 'gemini' or 'openai').
 *
 * Used as a Symfony service factory in services.yml:
 *
 *     comunidad.portal.llm.client:
 *         factory: ['comunidad\portal\service\llm\LlmClientFactory', 'create']
 *         arguments: ['@config']
 *
 * The Symfony service container caches the resolved instance, so
 * switching the provider at runtime requires `phpbbcli.php
 * cache:purge` (or the equivalent UI button) to take effect. The
 * ACP's provider-selector form triggers a cache:purge on save for
 * that reason.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

class LlmClientFactory
{
	public const PROVIDER_GEMINI = 'gemini';
	public const PROVIDER_OPENAI = 'openai';

	public static function supportedProviders(): array
	{
		return [self::PROVIDER_GEMINI, self::PROVIDER_OPENAI];
	}

	/**
	 * @throws \RuntimeException when the configured provider is unknown
	 *                           (signals a misconfiguration, not a runtime
	 *                           LLM failure)
	 */
	public static function create(\phpbb\config\config $config): LlmClient
	{
		$provider = isset($config['portal_ai_provider'])
			? trim((string) $config['portal_ai_provider'])
			: self::PROVIDER_GEMINI;

		switch ($provider) {
			case self::PROVIDER_OPENAI:
				return new OpenAIClient($config);

			case self::PROVIDER_GEMINI:
			case '':
				return new GeminiClient($config);

			default:
				throw new \RuntimeException(
					"Unknown portal_ai_provider '{$provider}'. Supported: "
					. implode(', ', self::supportedProviders())
				);
		}
	}
}
