<?php
if (!defined('IN_PHPBB')) {
	exit;
}

$lang = array_merge($lang, [
	'ACP_PORTAL_NEWS'				=> 'Noticias',
	'ACP_PORTAL_NEWS_EXPLAIN'		=> 'Configura desde qué foro se leen las noticias del portal y cuántas tarjetas mostrar en la página de inicio.',
	'ACP_PORTAL_NEWS_LEGEND'		=> 'Bloque de noticias',
	'ACP_PORTAL_NEWS_FORUM'			=> 'Foro fuente',
	'ACP_PORTAL_NEWS_FORUM_EXPLAIN'	=> 'El portal mostrará los temas más recientes de este foro como tarjetas de noticia. Elige "Desactivado" para ocultar el bloque.',
	'ACP_PORTAL_NEWS_LIMIT'			=> 'Cantidad de tarjetas',
	'ACP_PORTAL_NEWS_LIMIT_EXPLAIN'	=> 'Cuántas tarjetas de noticia mostrar (1–10).',
	'PORTAL_NEWS_NO_FORUM'			=> '— Desactivado —',
	'PORTAL_NEWS_SAVED'				=> 'Configuración de noticias del portal guardada.',
]);
