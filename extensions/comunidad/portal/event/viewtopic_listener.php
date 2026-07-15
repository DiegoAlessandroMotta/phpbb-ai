<?php
/**
 *
 * Portal Comunitario — viewtopic event listener.
 *
 * When the first post of a topic is rendered, loads the
 * portal_news_meta row (if any) and exposes the extracted
 * entities to the template so the
 * styles/all/template/event/viewtopic_body_postrow_before.html
 * hook can render a card above the post.
 *
 * Top-level template vars:
 *   S_PORTAL_NEWS_META_LOADED        (bool)  true if a meta row exists
 *   S_PORTAL_NEWS_EXTRACTING         (bool)  status = 0
 *   S_PORTAL_NEWS_EXTRACTION_OK      (bool)  status = 1
 *   S_PORTAL_NEWS_EXTRACTION_FAILED  (bool)  status = 2
 *   S_PORTAL_NEWS_EXTRACTION_PARTIAL (bool)  status = 3
 *   S_PORTAL_NEWS_HAS_PEOPLE         (bool)
 *   S_PORTAL_NEWS_HAS_ORGANIZATIONS  (bool)
 *   S_PORTAL_NEWS_HAS_PLACES         (bool)
 *   S_PORTAL_NEWS_HAS_DATES          (bool)
 *   S_PORTAL_NEWS_HAS_SOURCES        (bool)
 *   PORTAL_NEWS_ERROR_MESSAGE        (str)
 *
 * Top-level template blocks (one item per entity):
 *   portal_news_people         NAME, CONTEXT
 *   portal_news_organizations  NAME, CONTEXT
 *   portal_news_places         NAME, CONTEXT
 *   portal_news_dates          NAME, CONTEXT
 *   portal_news_sources        NAME, CONTEXT
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class viewtopic_listener implements EventSubscriberInterface
{
	/** @var \phpbb\config\config */
	private $config;
	/** @var \phpbb\db\driver\driver_interface */
	private $db;
	/** @var \phpbb\template\template */
	private $template;
	/** @var \phpbb\user */
	private $user;
	/** @var string */
	private $tablePrefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\template\template $template,
		\phpbb\user $user,
		string $tablePrefix
	) {
		$this->config = $config;
		$this->db = $db;
		$this->template = $template;
		$this->user = $user;
		$this->tablePrefix = $tablePrefix;

		$this->user->add_lang_ext('comunidad/portal', 'common');
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.viewtopic_modify_post_row' => 'modify_post_row',
		];
	}

	public function modify_post_row($event)
	{
		$postId = (int) ($event['post_id'] ?? 0);
		$firstPostId = (int) ($event['topic_data']['topic_first_post_id'] ?? 0);
		$topicId = (int) ($event['topic_id'] ?? 0);

		// Only fire on the first post of the topic.
		if ($postId !== $firstPostId || $topicId <= 0) {
			return;
		}

		$sql = 'SELECT extraction_status, entities_json, error_message, model_name
				FROM ' . $this->tablePrefix . 'portal_news_meta
				WHERE topic_id = ' . $topicId;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row) {
			return;
		}

		$status = (int) $row['extraction_status'];
		$entities = json_decode((string) $row['entities_json'], true);
		if (!is_array($entities)) {
			$entities = [];
		}

		$this->template->assign_vars([
			'S_PORTAL_NEWS_META_LOADED'       => true,
			'S_PORTAL_NEWS_EXTRACTING'        => $status === 0,
			'S_PORTAL_NEWS_EXTRACTION_OK'     => $status === 1,
			'S_PORTAL_NEWS_EXTRACTION_FAILED' => $status === 2,
			'S_PORTAL_NEWS_EXTRACTION_PARTIAL'=> $status === 3,
			'PORTAL_NEWS_ERROR_MESSAGE'       => (string) ($row['error_message'] ?? ''),
		]);

		$typeMap = [
			'people'        => 'name',
			'organizations' => 'name',
			'places'        => 'name',
			'dates'         => 'iso_date',
			'sources'       => 'name',
		];

		foreach ($typeMap as $blockSuffix => $nameKey) {
			$items = $entities[$blockSuffix] ?? [];
			$this->template->assign_var(
				'S_PORTAL_NEWS_HAS_' . strtoupper($blockSuffix),
				is_array($items) && !empty($items)
			);

			if (!is_array($items)) {
				continue;
			}

			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$this->template->assign_block_vars('portal_news_' . $blockSuffix, [
					'NAME'    => (string) ($item[$nameKey] ?? ''),
					'CONTEXT' => (string) (
						$item['role']
						?? $item['type']
						?? $item['context']
						?? ''
					),
				]);
			}
		}
	}
}
