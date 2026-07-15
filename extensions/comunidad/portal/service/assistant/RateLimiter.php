<?php
/**
 *
 * Portal Comunitario — global rate limiter for the writing assistant.
 *
 * Counts usage rows in `portal_assistant_usage` within the last hour
 * and decides whether the next request should be allowed. The window
 * is a rolling one-hour lookback, not a fixed clock hour, so a user
 * who waits one hour and one second after their first request of
 * the day is back at full quota.
 *
 * For the PoC the limit is global (one bucket shared by all users).
 * Promoting to per-user is a single change: switch the SELECT to
 * `WHERE user_id = ?` and add a user_id index.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\service\assistant;

class RateLimiter
{
	private \phpbb\config\config $config;
	private \phpbb\db\driver\driver_interface $db;
	private string $tablePrefix;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\db\driver\driver_interface $db,
		string $tablePrefix
	) {
		$this->config      = $config;
		$this->db          = $db;
		$this->tablePrefix = $tablePrefix;
	}

	/**
	 * How many requests have been made in the last hour, across all
	 * users. Cheap: relies on the i_requested_at index.
	 */
	public function usedInLastHour(): int
	{
		$sql = 'SELECT COUNT(*) AS c
			FROM ' . $this->tablePrefix . 'portal_assistant_usage
			WHERE requested_at >= ' . (time() - 3600);
		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['c'] ?? 0);
	}

	public function limitPerHour(): int
	{
		return max(0, (int) $this->config['portal_assistant_max_per_hour']);
	}

	public function remainingInLastHour(): int
	{
		return max(0, $this->limitPerHour() - $this->usedInLastHour());
	}

	/**
	 * True when a new request would be allowed under the current
	 * global cap. Callers should disable UI and return a clear error
	 * rather than letting the request hit the LLM when this is false.
	 */
	public function canMakeRequest(): bool
	{
		if (!(bool) $this->config['portal_assistant_enabled']) {
			return false;
		}
		return $this->usedInLastHour() < $this->limitPerHour();
	}
}
