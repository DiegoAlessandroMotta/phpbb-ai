<?php
/**
 *
 * Portal Comunitario — entity extraction cron task.
 *
 * Runs at most once per hour (see should_run). Picks up topics
 * with extraction_status = PENDING whose next_attempt_at is due,
 * calls the EntityExtractionService for each, and either:
 *   - lets the service write success / partial to the meta row
 *   - increments attempt_count and reschedules on transient errors
 *   - marks FAILED (no more retries) after max_attempts
 *
 * Skipped entirely when extraction is disabled or the LLM is not
 * configured. Failures are logged via the phpBB admin log.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\cron;

use comunidad\portal\service\extraction\EntityExtractionService;

class extract_news_entities extends \phpbb\cron\task\base
{
	/** @var \phpbb\config\config */
	private $config;
	/** @var \phpbb\db\driver\driver_interface */
	private $db;
	/** @var \phpbb\log\log */
	private $log;
	/** @var EntityExtractionService */
	private $extraction;
	/** @var string */
	private $tablePrefix;

	// Backoff schedule for retries: 1m, 5m, 15m.
	private const RETRY_DELAYS = [60, 300, 900];

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\log\log $log,
		EntityExtractionService $extraction,
		string $tablePrefix
	) {
		$this->config = $config;
		$this->db = $db;
		$this->log = $log;
		$this->extraction = $extraction;
		$this->tablePrefix = $tablePrefix;
	}

	public function run()
	{
		if (!$this->extraction->isConfigured()) {
			return;
		}

		$batchSize = (int) ($this->config['portal_extraction_batch_size'] ?? 20);
		if ($batchSize < 1) {
			$batchSize = 20;
		}
		$maxAttempts = max(1, (int) ($this->config['portal_extraction_max_attempts'] ?? 3));
		$now = time();

		// Pick PENDING topics whose next_attempt_at is due.
		// FAILED (status=2) is terminal — only the ACP button
		// can re-queue a failed topic.
		$sql = 'SELECT m.topic_id, m.attempt_count
				FROM ' . $this->tablePrefix . 'portal_news_meta m
				WHERE m.extraction_status = ' . EntityExtractionService::STATUS_PENDING . '
				  AND m.next_attempt_at <= ' . $now . '
				ORDER BY m.next_attempt_at ASC';
		$result = $this->db->sql_query_limit($sql, $batchSize);

		$processed = 0;
		$retrying = 0;
		$gaveUp = 0;

		while ($row = $this->db->sql_fetchrow($result)) {
			$topicId = (int) $row['topic_id'];
			$attempts = (int) $row['attempt_count'];
			try {
				$this->extraction->extractForTopic($topicId);
				$processed++;
			}
			catch (\Throwable $e) {
				$newAttempts = $attempts + 1;
				if ($newAttempts >= $maxAttempts) {
					$this->markFailed($topicId, $newAttempts, $e->getMessage());
					$gaveUp++;
				}
				else {
					$this->scheduleRetry($topicId, $newAttempts, $e->getMessage());
					$retrying++;
				}
			}
		}
		$this->db->sql_freeresult($result);

		$this->config->set('portal_extraction_last_run', $now);

		if ($processed > 0 || $retrying > 0 || $gaveUp > 0) {
			$this->log->add('admin', ANONYMOUS, '', 'LOG_PORTAL_EXTRACTION_RUN', $now, [
				'PROCESSED' => $processed,
				'RETRYING'  => $retrying,
				'GAVE_UP'   => $gaveUp,
			]);
		}
	}

	private function scheduleRetry(int $topicId, int $newAttempts, string $error): void
	{
		$delay = self::RETRY_DELAYS[min($newAttempts - 1, count(self::RETRY_DELAYS) - 1)];
		$next = time() + $delay;
		$sql = 'UPDATE ' . $this->tablePrefix . 'portal_news_meta
				SET next_attempt_at = ' . $next . ',
				    attempt_count = ' . $newAttempts . ',
				    error_message = "' . $this->db->sql_escape($error) . '"
				WHERE topic_id = ' . $topicId;
		$this->db->sql_query($sql);
	}

	private function markFailed(int $topicId, int $newAttempts, string $error): void
	{
		$sql = 'UPDATE ' . $this->tablePrefix . 'portal_news_meta
				SET extraction_status = ' . EntityExtractionService::STATUS_FAILED . ',
				    next_attempt_at = 0,
				    attempt_count = ' . $newAttempts . ',
				    error_message = "' . $this->db->sql_escape($error) . '"
				WHERE topic_id = ' . $topicId;
		$this->db->sql_query($sql);
	}

	public function is_runnable()
	{
		return !empty($this->config['portal_extraction_enabled']);
	}

	public function should_run()
	{
		if (empty($this->config['portal_extraction_enabled'])) {
			return false;
		}
		$last = (int) ($this->config['portal_extraction_last_run'] ?? 0);
		// Once per hour. phpBB's cron is lazy (fires on forum visits),
		// so this means "first visit after the hour has elapsed".
		return $last < time() - 3600;
	}
}
