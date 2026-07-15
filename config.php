<?php
//
// phpBB configuration. Reads every value from env vars injected by
// docker-compose. Configure via .env (dev) or your platform's
// env-var UI (Dokploy, Portainer, etc.) — never edit this file.
//
// IMPORTANT — install flow:
//   The CLI installer (run on first boot by docker/entrypoint.sh)
//   refuses to run if a non-empty config.php already exists. So this
//   file is NOT staged into /var/www/html on the first-boot copy.
//   The entrypoint runs the installer first (which writes its own
//   config.php) and then overwrites that with this env-var driven
//   version. The end state is: schema in DB, config.php env-var
//   driven, no /var/www/html/install/ left around.
//

$dbms           = getenv('PHPBB_DBMS')           ?: 'phpbb\\db\\driver\\mysqli';
$dbhost         = getenv('PHPBB_DB_HOST')        ?: 'localhost';
$dbport         = getenv('PHPBB_DB_PORT')        ?: '';
$dbname         = getenv('PHPBB_DB_NAME')        ?: 'phpbb';
$dbuser         = getenv('PHPBB_DB_USER')        ?: 'phpbb';
$dbpasswd       = getenv('PHPBB_DB_PASSWORD')    ?: '';
$table_prefix   = getenv('PHPBB_TABLE_PREFIX')   ?: 'phpbb_';
$acm_type       = getenv('PHPBB_ACM_TYPE')       ?: 'phpbb\\cache\\driver\\file';
$load_extensions = '';

@define('PHPBB_INSTALLED',   getenv('PHPBB_INSTALLED') === '1');
@define('PHPBB_ENVIRONMENT', getenv('PHPBB_ENVIRONMENT') ?: 'production');

// Uncomment to enable phpBB's debug mode (verbose errors, slow query log).
// @define('DEBUG', true);
@define('DEBUG_CONTAINER',   getenv('PHPBB_DEBUG') === '1');

