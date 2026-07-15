<?php
/**
 *
 * Portal Comunitario — LLM provider abstraction.
 *
 * Concrete implementations (Gemini today, OpenAI/Anthropic later)
 * live alongside this interface. The rest of the extension depends
 * on this contract, not on a specific vendor.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

interface LlmClient
{
	/**
	 * True if the provider has everything it needs to make a call
	 * (API key set, credentials loaded, model available). Callers
	 * should skip work when this returns false rather than relying
	 * on a downstream error.
	 */
	public function is_configured(): bool;

	/**
	 * Send a structured-extraction request to the LLM and return
	 * the parsed response.
	 *
	 * Implementations MUST honor $jsonSchema (treating it as
	 * authoritative for the response shape) and MUST throw
	 * LlmException on transport, auth, or schema errors.
	 *
	 * @param string $systemPrompt    System / role instructions.
	 * @param string $userText        The content to extract from.
	 * @param array  $jsonSchema      Expected JSON Schema for the
	 *                                response (JSON Schema 2020-12).
	 * @param int    $maxOutputTokens Hard cap on response size.
	 *
	 * @throws LlmException
	 */
	public function extract(
		string $systemPrompt,
		string $userText,
		array $jsonSchema,
		int $maxOutputTokens = 1000
	): LlmResponse;

	/**
	 * Send an unconstrained generation request to the LLM and return
	 * the response as plain text.
	 *
	 * Use this for free-form tasks (summarization, rewriting,
	 * title suggestions, etc.). Use extract() when the output must
	 * conform to a JSON Schema. Implementations MUST throw
	 * LlmException on transport or auth errors.
	 *
	 * The returned LlmResponse::$parsed will be `['text' => $text]`
	 * to keep shape parity with extract() callers.
	 *
	 * @param string $systemPrompt    System / role instructions.
	 * @param string $userText        The content to act on.
	 * @param int    $maxOutputTokens Hard cap on response size.
	 *
	 * @throws LlmException
	 */
	public function generateText(
		string $systemPrompt,
		string $userText,
		int $maxOutputTokens = 900
	): LlmResponse;
}
