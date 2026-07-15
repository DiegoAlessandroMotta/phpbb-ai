<?php
/**
 *
 * Portal Comunitario — assistant action registry.
 *
 * Owns the canonical list of assistant actions, their system prompt,
 * and the per-action prompt body. Keeping these in one place means the
 * controller, the service, the UI button labels, and the future ACP
 * picker all draw from the same source of truth.
 *
 * Prompts are tuned for Spanish-language output in a community forum
 * context. Changing them is a code change, not a config change, on
 * purpose: prompts are product behavior, not ops configuration.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\assistant;

class ActionRegistry
{
	public const SYSTEM_PROMPT = 'Eres el asistente editorial de un foro comunitario. Responde siempre en español. No añadas hechos que no aparezcan en el texto del usuario.';

	/**
	 * Map of action id => Spanish prompt body.
	 *
	 * @return array<string, string>
	 */
	public static function prompts(): array
	{
		return [
			'improve'  => 'Mejora la claridad, ortografía y estructura. Conserva el significado y devuelve únicamente el texto final.',
			'friendly' => 'Reescribe con un tono cordial, constructivo e inclusivo para una comunidad en línea. Devuelve únicamente el texto final.',
			'summary'  => 'Resume el contenido en español en un máximo de cinco puntos breves. No inventes información.',
			'title'    => 'Propón cinco títulos breves y atractivos para una publicación basada en este contenido.',
			'reply'    => 'Redacta una respuesta breve, cordial y útil para contestar este mensaje privado. No inventes compromisos ni datos personales. Devuelve únicamente el borrador de respuesta.',
		];
	}

	public static function isValid(string $action): bool
	{
		return array_key_exists($action, self::prompts());
	}

	public static function promptFor(string $action): string
	{
		return self::prompts()[$action];
	}
}
