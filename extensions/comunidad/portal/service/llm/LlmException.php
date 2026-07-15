<?php
/**
 *
 * Portal Comunitario — LLM provider exception.
 *
 * Wraps transport, auth, and schema failures from any LlmClient
 * implementation. getCode() returns the HTTP status when one is
 * available, or 0 for transport / parse errors.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm;

class LlmException extends \RuntimeException
{
}
