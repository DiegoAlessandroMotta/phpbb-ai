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
}
