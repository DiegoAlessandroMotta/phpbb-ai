<?php
/**
 * Check whether a phpBB extension is already enabled.
 *
 * Reads the phpbb_ext table directly with the same mysqli connection
 * the rest of the entrypoint uses. Exits 0 if the extension is on,
 * 1 if not (or if the query failed). Stdout is "yes" or "no" so the
 * caller can branch in shell without parsing error text.
 *
 * Required env vars: PHPBB_DB_HOST, PHPBB_DB_PORT, PHPBB_DB_USER,
 *                    PHPBB_DB_PASSWORD, PHPBB_DB_NAME.
 * Argv[1]: vendor/ext name to look up (e.g. "comunidad/portal").
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: check-ext-enabled.php <vendor/ext>\n");
    exit(2);
}

$ext_name = $argv[1];

$dbhost        = getenv('PHPBB_DB_HOST')     ?: 'db';
$dbport        = getenv('PHPBB_DB_PORT')     ?: '3306';
$dbuser        = getenv('PHPBB_DB_USER')     ?: 'phpbb';
$dbpass        = getenv('PHPBB_DB_PASSWORD') ?: '';
$dbname        = getenv('PHPBB_DB_NAME')     ?: 'phpbb';
$table_prefix  = getenv('PHPBB_TABLE_PREFIX') ?: 'phpbb_';

$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname, (int) $dbport);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "[check-ext-enabled] DB connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$ext_tbl = $table_prefix . 'ext';
$stmt = $mysqli->prepare("SELECT 1 FROM {$ext_tbl} WHERE ext_name = ? LIMIT 1");
if (!$stmt) {
    fwrite(STDERR, "[check-ext-enabled] prepare failed: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}
$stmt->bind_param('s', $ext_name);
$stmt->execute();
$stmt->store_result();
$enabled = $stmt->num_rows > 0;
$stmt->close();
$mysqli->close();

echo $enabled ? "yes" : "no";
exit($enabled ? 0 : 1);
