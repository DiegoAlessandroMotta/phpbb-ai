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
#   2.6. Sync phpBB extensions from /etc/phpbb/extensions/ into
#      /var/www/html/ext/. Source of truth for extension code lives
#      on the host (./extensions/ in dev, baked into the image in
#      prod). Overwrite on every start so host edits take effect on
#      restart.
#   2.7. Enable extensions declared in PHPBB_EXTENSIONS (comma-
#      separated vendor/ext names). Idempotent; failures are logged
#      but do not abort the entrypoint.
#   2.8. Sync phpBB styles from /etc/phpbb/styles/ into
#      /var/www/html/styles/. Same declarative pattern as extensions
#      and config.php: host is source of truth, overwrite on every
#      container start. phpBB picks up new styles on the next request
#      (it scans the directory on first hit).
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

# --- 2.6. Sync phpBB extensions from /etc/phpbb/extensions/ ----------
# Source of truth for extension code is /etc/phpbb/extensions/ (bind-
# mount from ./extensions/ in dev, COPY in prod). On every container
# start we copy each vendor/ext/ subtree into /var/www/html/ext/, so
# host edits to extension code take effect on restart. Overwrite —
# same declarative principle as config.php. Mount is :ro, so the
# container cannot mutate your working copy.
#
# Layout expected: /etc/phpbb/extensions/<vendor>/<ext>/...
# (phpBB's standard ext/<vendor>/<ext>/ layout, minus the leading
# "ext/" that bundles tend to ship with).
if [ -d /etc/phpbb/extensions ]; then
    mkdir -p /var/www/html/ext
    for ext_dir in /etc/phpbb/extensions/*/*/; do
        [ -d "$ext_dir" ] || continue
        rel="${ext_dir#/etc/phpbb/extensions/}"
        rel="${rel%/}"
        [ -z "$rel" ] && continue
        if [ -f "$ext_dir/ext.php" ]; then
            mkdir -p "/var/www/html/ext/$rel"
            cp -r "$ext_dir"/. "/var/www/html/ext/$rel/"
            echo "[entrypoint] Synced extension: $rel"
        fi
    done
fi

# --- 2.7. Enable extensions declared in PHPBB_EXTENSIONS -------------
# Comma-separated list of vendor/ext names (e.g. "comunidad/portal").
# phpbbcli.php extension:enable returns exit 1 when the extension is
# already enabled, so we first check the phpbb_ext table directly
# (docker/check-ext-enabled.php) and skip the CLI call when there's
# nothing to do. This makes the phase truly idempotent and keeps the
# boot log clean on every restart. Failures from a real enable
# attempt are logged but do NOT abort the entrypoint: the user can
# fix the config and restart.
if [ -n "${PHPBB_EXTENSIONS:-}" ]; then
    # cache/ must be writable by www-data before the CLI runs.
    mkdir -p /var/www/html/cache
    chown www-data:www-data /var/www/html/cache 2>/dev/null || true
    # Save and restore IFS so the comma split is scoped to this block.
    OLD_IFS="$IFS"
    IFS=','
    for ext in $PHPBB_EXTENSIONS; do
        IFS="$OLD_IFS"
        ext=$(printf '%s' "$ext" | tr -d '[:space:]')
        [ -z "$ext" ] && continue
        # Skip the CLI call if the extension is already enabled.
        # exit 0 = enabled, 1 = not enabled, anything else = error.
        set +e
        check_output=$(php /etc/phpbb/check-ext-enabled.php "$ext" 2>&1)
        check_exit=$?
        set -e
        if [ $check_exit -eq 0 ]; then
            echo "[entrypoint] Extension already enabled: $ext"
            IFS=','
            continue
        elif [ $check_exit -ne 1 ]; then
            # Real error (DB connection, etc). Log it but still try
            # the CLI — better to attempt the enable than to silently
            # skip it.
            echo "[entrypoint] WARNING: could not check '$ext' state (exit $check_exit)."
            echo "$check_output" | sed 's/^/[entrypoint]   /'
        fi
        set +e
        output=$(su www-data -s /bin/sh -c "cd /var/www/html && php bin/phpbbcli.php extension:enable '$ext' 2>&1")
        enable_exit=$?
        set -e
        if [ $enable_exit -eq 0 ]; then
            echo "[entrypoint] Enabled extension: $ext"
        else
            echo "[entrypoint] WARNING: could not enable '$ext' (exit $enable_exit)."
            echo "$output" | sed 's/^/[entrypoint]   /'
        fi
        IFS=','
    done
    IFS="$OLD_IFS"
fi

# --- 2.8. Sync phpBB styles from /etc/phpbb/styles/ ------------------
# Same pattern as extensions (phase 2.6): each subdirectory of
# /etc/phpbb/styles/ is a style. Overwrite on every start so host
# edits to CSS / templates take effect on restart. phpBB discovers
# styles by scanning /var/www/html/styles/ on the first request
# after this sync, and records them in the phpbb_styles table.
# Style activation (default_style) is handled separately via a
# SQL UPDATE — see docker/post-bootstrap.d/ if you need it.
if [ -d /etc/phpbb/styles ]; then
    mkdir -p /var/www/html/styles
    for style_dir in /etc/phpbb/styles/*/; do
        [ -d "$style_dir" ] || continue
        rel="${style_dir#/etc/phpbb/styles/}"
        rel="${rel%/}"
        [ -z "$rel" ] && continue
        # Copy any subdir: real styles have style.cfg, the "all"
        # pseudo-style does not (it's the global fallback Twig looks
        # in for missing templates). We want both.
        mkdir -p "/var/www/html/styles/$rel"
        cp -r "$style_dir"/. "/var/www/html/styles/$rel/"
        if [ -f "$style_dir/style.cfg" ]; then
            echo "[entrypoint] Synced style: $rel"
        else
            echo "[entrypoint] Synced style assets: $rel"
        fi
    done
fi

# --- 2.9. Activate default style declared in PHPBB_DEFAULT_STYLE ------
# phpBB's installer sets default_style=1 (prosilver) and
# override_user_style=0, so the default config is ignored and every
# user (including Anonymous) uses their user_style=1=prosilver. To
# ship a different default style, we need all three: register the
# style, point default_style at it, and flip override_user_style=1.
# docker/activate-style.php does all three, idempotently.
if [ -n "${PHPBB_DEFAULT_STYLE:-}" ]; then
    php /etc/phpbb/activate-style.php || echo "[entrypoint] WARNING: activate-style failed (non-fatal)"
fi

# --- 3. Chown ----------------------------------------------------------
chown -R www-data:www-data /var/www/html

# --- 4. Hand off to Apache --------------------------------------------
exec docker-php-entrypoint "$@"



