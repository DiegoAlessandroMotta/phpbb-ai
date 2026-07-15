<?php
/**
 *
 * Portal Comunitario — posting event listener.
 *
 * Marks a freshly-created topic for entity extraction when:
 *   - the post is a new topic (mode = "post"), not a reply/edit
 *   - the topic lives in the configured news forum
 *   - extraction is enabled
 *
 * Idempotent: INSERT IGNORE — a topic already in
 * portal_news_meta is left untouched, so re-firing the event
 * (e.g. on resubmit) does not reset a successful extraction.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class posting_listener implements EventSubscriberInterface
{
	/** @var \phpbb\config\config */
	private $config;
	/** @var \phpbb\db\driver\driver_interface */
	private $db;
	/** @var string */
	private $tablePrefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		string $tablePrefix
	) {
		$this->config = $config;
		$this->db = $db;
		$this->tablePrefix = $tablePrefix;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.submit_post_end' => 'mark_for_extraction',
		];
	}

	public function mark_for_extraction($event)
	{
		$data = $event['data'];
		$mode = $event['mode'] ?? '';

		// Only act on new-topic submissions. Replies, edits, and
		// quotes do not produce new first-post content.
		if ($mode !== 'post') {
			return;
		}

		$topicId = (int) ($data['topic_id'] ?? 0);
		$forumId = (int) ($data['forum_id'] ?? 0);

		if ($topicId <= 0 || $forumId <= 0) {
			return;
		}

		// Feature must be enabled and the news forum must be set.
		if (empty($this->config['portal_extraction_enabled'])) {
			return;
		}

		$newsForumId = (int) ($this->config['portal_news_forum_id'] ?? 0);
		if ($newsForumId <= 0 || $newsForumId !== $forumId) {
			return;
		}

		// INSERT IGNORE — won't overwrite an existing row, so
		// a successful extraction is not clobbered by a re-submit.
		$sql = 'INSERT IGNORE INTO ' . $this->tablePrefix . 'portal_news_meta
				(topic_id, extraction_status, next_attempt_at, attempt_count)
				VALUES (' . $topicId . ', 0, ' . time() . ', 0)';
		$this->db->sql_query($sql);
	}
}
