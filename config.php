<?php
//
// phpBB configuration. Reads every value from env vars injected by
// docker-compose. Configure via .env (dev) or your platform's
// env-var UI (Dokploy, Portainer, etc.) — never edit this file.
//
// Mounted read-only at /var/www/html/config.php. The web/CLI
// installer will detect it on first run and use the DB credentials
// from here to populate the schema. After install, schema lives in
// the database, not in this file.
//
// Note: PHPBB_INSTALLED stays false on purpose. The installer uses
// the schema state to decide what to do, not this constant. Flip
// it to true in your env after a successful install to skip the
// "install not run" guard checks in phpBB's setup.

$dbms           = getenv('PHPBB_DBMS')           ?: 'mysqli';
$dbhost         = getenv('PHPBB_DB_HOST')        ?: 'localhost';
$dbport         = getenv('PHPBB_DB_PORT')        ?: '';
$dbname         = getenv('PHPBB_DB_NAME')        ?: 'phpbb';
$dbuser         = getenv('PHPBB_DB_USER')        ?: 'phpbb';
$dbpasswd       = getenv('PHPBB_DB_PASSWORD')    ?: '';
$table_prefix   = getenv('PHPBB_TABLE_PREFIX')   ?: 'phpbb_';
$acm_type       = getenv('PHPBB_ACM_TYPE')       ?: 'file';
$load_extensions = '';

@define('PHPBB_INSTALLED',   getenv('PHPBB_INSTALLED') === '1');
@define('PHPBB_ENVIRONMENT', getenv('PHPBB_ENVIRONMENT') ?: 'production');

// Uncomment to enable phpBB's debug mode (verbose errors, slow query log).
// @define('DEBUG', true);
@define('DEBUG_CONTAINER',   getenv('PHPBB_DEBUG') === '1');
