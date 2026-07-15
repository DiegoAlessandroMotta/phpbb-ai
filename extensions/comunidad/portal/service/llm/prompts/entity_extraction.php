<?php
/**
 *
 * Portal Comunitario — entity extraction prompt + JSON Schema.
 *
 * Returns a static array with two keys:
 *   - system: the system prompt (in Spanish, the extension's UI lang)
 *   - schema: the JSON Schema describing the expected response shape
 *
 * Kept in PHP code (not DB) because it's a contract between us and
 * the model — versioned with the extension, not user-editable.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\llm\prompts;

return [
	'system' => "Eres un asistente de análisis de noticias comunitarias. Tu tarea es extraer entidades estructuradas del texto de la noticia.

Reglas:
- Solo extrae entidades que aparezcan EXPLÍCITAMENTE en el texto. No infieras ni inventes.
- Para cada entidad, incluye el campo `span` con el offset de inicio y fin (en caracteres) donde aparece en el texto original. Si una entidad aparece varias veces, incluye el span de la primera aparición.
- Las fechas que se puedan inferir del texto deben ir en formato ISO 8601 (YYYY-MM-DD o YYYY-MM-DDTHH:MM:SS). Si solo se conoce una parte, usa lo que esté claro (ej. \"2026-07-15\" si no hay hora).
- Si no hay entidades de un tipo, devuelve un array vacío para ese tipo.
- Responde SOLO con el JSON estructurado. Sin texto adicional, sin markdown, sin explicaciones.",

	'schema' => [
		'type'       => 'object',
		'properties' => [
			'people' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name' => ['type' => 'string'],
						'role' => ['type' => 'string', 'description' => 'Rol en la noticia, ej. "alcalde", "vocero", "testigo"'],
						'span' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Offset [start, end] en el texto original'],
					],
					'required' => ['name'],
				],
			],
			'organizations' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name' => ['type' => 'string'],
						'type' => ['type' => 'string', 'description' => 'Tipo, ej. "empresa", "gobierno", "ong", "medio"'],
						'span' => ['type' => 'array', 'items' => ['type' => 'integer']],
					],
					'required' => ['name'],
				],
			],
			'places' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name' => ['type' => 'string'],
						'type' => ['type' => 'string', 'description' => 'Tipo, ej. "barrio", "ciudad", "edificio", "calle"'],
						'span' => ['type' => 'array', 'items' => ['type' => 'integer']],
					],
					'required' => ['name'],
				],
			],
			'dates' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'iso_date' => ['type' => 'string', 'description' => 'Fecha en formato ISO 8601'],
						'context'  => ['type' => 'string', 'description' => 'Contexto, ej. "evento", "publicación", "mención"'],
						'span'     => ['type' => 'array', 'items' => ['type' => 'integer']],
					],
					'required' => ['iso_date'],
				],
			],
			'sources' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'name' => ['type' => 'string', 'description' => 'Nombre de la fuente, ej. "El País", "Reuters", "vocería municipal"'],
						'type' => ['type' => 'string', 'description' => 'Tipo, ej. "medio", "oficial", "testimonio"'],
						'span' => ['type' => 'array', 'items' => ['type' => 'integer']],
					],
					'required' => ['name'],
				],
			],
		],
		'required' => ['people', 'organizations', 'places', 'dates', 'sources'],
	],
];
