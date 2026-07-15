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

	'ACP_PORTAL_EXTRACTION'             => 'Entity extraction',
	'ACP_PORTAL_EXTRACTION_EXPLAIN'     => 'List entity extractions from news posts with their status, attempt count, tokens, and error messages. Re-extract any post to force a new LLM call.',
	'ACP_PORTAL_EXTRACTION_STATUS'      => 'Status',
	'ACP_PORTAL_EXTRACTION_LIST'        => 'Recent extractions',
	'ACP_PORTAL_EXTRACTION_DISABLED'    => 'Extraction is disabled. Enable it in the portal configuration to process topics.',
	'ACP_PORTAL_EXTRACTION_NO_LLM'      => 'The LLM provider is not configured. Set the API key in phpbb_config.portal_ai_gemini_api_key or via the GEMINI_API_KEY environment variable.',
	'ACP_PORTAL_EXTRACTION_LAST_RUN'    => 'Last cron run',
	'ACP_PORTAL_EXTRACTION_TOPIC'       => 'Post',
	'ACP_PORTAL_EXTRACTION_STATUS_COL'  => 'Status',
	'ACP_PORTAL_EXTRACTION_EXTRACTED'   => 'Extracted',
	'ACP_PORTAL_EXTRACTION_ATTEMPTS'    => 'Attempts',
	'ACP_PORTAL_EXTRACTION_TOKENS'      => 'Tokens (prompt / response)',
	'ACP_PORTAL_EXTRACTION_MODEL'       => 'Model',
	'ACP_PORTAL_EXTRACTION_ACTIONS'     => 'Actions',
	'ACP_PORTAL_EXTRACTION_EMPTY'       => 'No extractions yet. Posts from the configured news forum will be processed automatically when created.',
	'ACP_PORTAL_EXTRACTION_REEXTRACT'   => 'Re-extract',
	'ACP_PORTAL_EXTRACTION_SUCCESS'     => 'Extraction completed successfully.',
	'ACP_PORTAL_EXTRACTION_FAILED'      => 'Extraction failed.',
	'ACP_PORTAL_EXTRACTION_INVALID_TOPIC' => 'Invalid topic.',
	'PORTAL_EXTRACTION_STATUS_0'        => 'Pending',
	'PORTAL_EXTRACTION_STATUS_1'        => 'OK',
	'PORTAL_EXTRACTION_STATUS_2'        => 'Failed',
	'PORTAL_EXTRACTION_STATUS_3'        => 'Partial',
]);
