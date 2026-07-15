#!/bin/sh
#
# phpBB entrypoint. Runs once on container start as root, then hands
# off to the base image's docker-php-entrypoint (which starts Apache
# as www-data).
#
# Phases, in order:
#   1. First-boot copy: phpBB lives in /usr/src/phpbb (set up in the
#      Dockerfile, WITHOUT a config.php — the CLI installer refuses
#      to run if a config.php is present). We copy the source to
#      /var/www/html only if config.php isn't already there, so
#      named volumes and bind-mounts both work.
#   2. Bootstrap: if .bootstrapped isn't present, run the CLI
#      installer with a config built from envsubst'd install-config
#      template. The installer writes its own config.php; phase 2.5
#      overwrites that with the env-var driven version. After it
#      succeeds, remove the install/ directory (security) and write
#      the marker so subsequent boots skip this phase.
#   2.5. Always-sync config.php: on EVERY container start, overwrite
#      /var/www/html/config.php from /etc/phpbb/config.php (the env-
#      var driven source). This is what makes changes in .env take
#      effect on restart — without it, the bind-mount snapshot from
#      the first run would mask the new env values.
#   3. Chown: make sure the doc root belongs to www-data.
#   4. Hand off to the base image's entrypoint (starts Apache).

set -e

# --- 0. Defaults for installer template vars ---------------------------
# GNU envsubst (from gettext) does NOT support the ${VAR:-default}
# shell syntax — only ${VAR}. So we apply defaults here, before
# envsubst runs, and the template can use plain ${VAR}.
: "${PHPBB_ADMIN_NAME:=admin}"
: "${PHPBB_ADMIN_PASSWORD:=admin123}"
: "${PHPBB_ADMIN_EMAIL:=admin@example.com}"
: "${PHPBB_BOARD_NAME:=wiki-forum-dev}"
: "${PHPBB_BOARD_DESCRIPTION:=phpBB forum for local development}"
: "${PHPBB_BOARD_LANG:=en}"
: "${PHPBB_COOKIE_SECURE:=true}"
: "${PHPBB_WEB_HOST:=forum.local}"
: "${PHPBB_WEB_PROTOCOL:=https}"
: "${PHPBB_WEB_PORT:=443}"
: "${PHPBB_DBMS:=mysqli}"
: "${PHPBB_DB_HOST:=db}"
: "${PHPBB_DB_USER:=phpbb}"
: "${PHPBB_DB_PASSWORD:=phpbb}"
: "${PHPBB_DB_NAME:=phpbb}"
: "${PHPBB_TABLE_PREFIX:=phpbb_}"
# PHPBB_DB_PORT is the in-container port (MariaDB listens on 3306
# inside the network). It is NOT the host port that docker-compose
# uses for port mapping (which is also PHPBB_DB_PORT in .env, but
# the mapping is resolved at compose-up time, before the entrypoint
# runs, so we can safely hardcode this for phpBB's own use).
PHPBB_DB_PORT=3306
export PHPBB_ADMIN_NAME PHPBB_ADMIN_PASSWORD PHPBB_ADMIN_EMAIL
export PHPBB_BOARD_NAME PHPBB_BOARD_DESCRIPTION PHPBB_BOARD_LANG
export PHPBB_COOKIE_SECURE PHPBB_WEB_HOST PHPBB_WEB_PROTOCOL PHPBB_WEB_PORT
export PHPBB_DBMS PHPBB_DB_HOST PHPBB_DB_PORT PHPBB_DB_USER
export PHPBB_DB_PASSWORD PHPBB_DB_NAME PHPBB_TABLE_PREFIX

# --- 1. First-boot copy -------------------------------------------------
if [ ! -f /var/www/html/config.php ] && [ -d /usr/src/phpbb ]; then
    echo "[entrypoint] First boot: copying phpBB into /var/www/html ..."
    cp -a /usr/src/phpbb/. /var/www/html/
fi

# --- 2. Bootstrap (CLI installer) --------------------------------------
if [ ! -f /var/www/html/.bootstrapped ] && [ -f /etc/phpbb/install-config.yml.template ]; then
    echo "[entrypoint] Bootstrapping phpBB via CLI installer ..."
    # Render the template into /tmp (ephemeral — no need to persist).
    envsubst < /etc/phpbb/install-config.yml.template > /tmp/install-config.yml
    # Run as www-data: the installer writes to the doc root, runs
    # cache generators, etc., and should not be running as root.
    set +e
    su www-data -s /bin/sh -c "cd /var/www/html && php install/phpbbcli.php install /tmp/install-config.yml"
    install_exit=$?
    set -e
    rm -f /tmp/install-config.yml
    if [ $install_exit -ne 0 ]; then
        echo "[entrypoint] CLI installer FAILED (exit $install_exit). Leaving install/ in place for debugging. NOT marking as bootstrapped — next boot will retry."
        exit 1
    fi
    # Remove the web/CLI installer directory. It must NOT be
    # reachable from a browser in prod — phpBB does not auto-delete
    # it after install.
    rm -rf /var/www/html/install
    # Mark as bootstrapped so we never re-run. The marker lives in
    # the doc root, so it persists across container restarts inside
    # a named volume (prod) or a bind-mount (dev).
    touch /var/www/html/.bootstrapped
    echo "[entrypoint] Bootstrap complete."
fi

# --- 2.5. Always sync config.php from the env-var driven source --------
# The env-var driven config.php staged at /etc/phpbb is the source of
# truth for runtime config. We overwrite /var/www/html/config.php on
# EVERY container start so that:
#   - On first boot, it wins over the installer's own config.php.
#   - On subsequent boots, the bind-mount snapshot from the first run
#     is replaced with the current env-var driven file, so changes in
#     .env take effect on the next restart (the bind-mount would
#     otherwise mask them).
# This intentionally discards manual edits to config.php — env vars
# are the only supported way to change phpBB config.
if [ -f /etc/phpbb/config.php ]; then
    cp /etc/phpbb/config.php /var/www/html/config.php
    echo "[entrypoint] Synced config.php from env-var driven source."
fi

# --- 3. Chown ----------------------------------------------------------
chown -R www-data:www-data /var/www/html

# --- 4. Hand off to Apache --------------------------------------------
exec docker-php-entrypoint "$@"



