<?php
/**
 *
 * Portal Comunitario — LLM response DTO.
 *
 * Holds the raw text the model returned plus the already-decoded
 * JSON (when the request used a jsonSchema), plus token usage for
 * cost tracking. Readonly to keep callers honest.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

class LlmResponse
{
	public function __construct(
		public readonly string $text,
		public readonly array $parsed,
		public readonly int $promptTokens,
		public readonly int $completionTokens,
		public readonly string $model,
	) {
	}
}
