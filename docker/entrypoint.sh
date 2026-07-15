#!/bin/sh
#
# phpBB entrypoint. Runs once on container start as root, then hands
# off to the base image's docker-php-entrypoint (which starts Apache
# as www-data).
#
# Why a staging dir + first-boot copy (same pattern as the official
# WordPress image):
#   * Named volume on /var/www/html (prod): starts empty, gets
#     populated here, content persists across container recreations.
#   * Bind-mount ./html → /var/www/html (dev): starts empty on first
#     run, gets populated, then the user can edit the working copy
#     in their editor. Subsequent restarts are a no-op.
#   * If /var/www/html already has phpBB (typical after the first
#     boot, or after the user has run the install wizard and written
#     config.php), we don't touch it.

set -e

if [ ! -f /var/www/html/config.php ] && [ -d /usr/src/phpbb ]; then
    echo "[entrypoint] First boot: copying phpBB into /var/www/html ..."
    cp -a /usr/src/phpbb/. /var/www/html/
fi

# Apache runs as www-data; make sure it owns the doc root. chown is
# a no-op when files already belong to www-data (typical after the
# first-boot copy above). On bind-mounts where the host UID differs
# from 33 (www-data), this fixes permissions on every boot.
chown -R www-data:www-data /var/www/html

exec docker-php-entrypoint "$@"
