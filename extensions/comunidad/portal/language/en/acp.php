<?php
if (!defined('IN_PHPBB')) {
	exit;
}

$lang = array_merge($lang, [
	'ACP_PORTAL_NEWS'				=> 'News',
	'ACP_PORTAL_NEWS_EXPLAIN'		=> 'Configure which forum the portal reads news from, and how many cards to show on the home page.',
	'ACP_PORTAL_NEWS_LEGEND'		=> 'News block',
	'ACP_PORTAL_NEWS_FORUM'			=> 'Source forum',
	'ACP_PORTAL_NEWS_FORUM_EXPLAIN'	=> 'The portal will show the most recent topics from this forum as news cards. Pick "Disabled" to hide the block entirely.',
	'ACP_PORTAL_NEWS_LIMIT'			=> 'Number of cards',
	'ACP_PORTAL_NEWS_LIMIT_EXPLAIN'	=> 'How many news cards to display (1–10).',
	'PORTAL_NEWS_NO_FORUM'			=> '— Disabled —',
	'PORTAL_NEWS_SAVED'				=> 'Portal news settings saved.',
]);
