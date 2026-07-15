# syntax=docker/dockerfile:1.6
#
# phpBB 3.3.x custom image.
# Base: php:8.3-apache — ships PHP 8.3 + Apache + mod_rewrite. We
# install the few extensions phpBB needs below (mysqli, gd, intl,
# zip, opcache) and the runtime libs they link against.

ARG PHP_VERSION=8.3
ARG PHPBB_VERSION=3.3.17
ARG PHPBB_TARBALL_URL=https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.tar.bz2

FROM php:${PHP_VERSION}-apache

ARG PHPBB_TARBALL_URL

# Build deps for the PHP extensions phpBB needs.
# - bzip2: required to extract the official .tar.bz2 release.
# - gettext-base: provides envsubst, used by the entrypoint to
#   expand ${VAR} placeholders in install-config.yml.template.
# - libonig-dev: mbstring (occasionally missing in slim images).
# - libzip-dev / libicu-dev: zip / intl.
# - libpng-dev / libjpeg62-turbo-dev / libfreetype6-dev: gd
#   (CAPTCHA + avatars need freetype + jpeg support).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bzip2 \
        gettext-base \
        libonig-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure + install PHP extensions in a single layer.
# opcache is a "should have" — phpBB's template/theme cache is large
# and opcache gives a noticeable speedup.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mysqli \
        opcache \
        zip

# Enable Apache mod_rewrite — phpBB's .htaccess does the SEO-URL work.
RUN a2enmod rewrite

# Custom PHP overrides, picked up from /usr/local/etc/php/conf.d/.
# The "zz-" prefix makes this file load last, so its values win.
COPY docker/php/zz-phpbb.ini /usr/local/etc/php/conf.d/zz-phpbb.ini

# Download phpBB to a staging dir, NOT directly to /var/www/html.
# The entrypoint (docker/entrypoint.sh) copies from /usr/src/phpbb
# into /var/www/html on first boot only. That makes the same image
# work with a named volume (prod) OR a bind-mount ./html (dev)
# without losing the source on container rebuild.
#
# Note: we deliberately do NOT put config.php in the staging dir.
# The CLI installer refuses to run if a non-empty config.php is
# present in the doc root (it thinks phpBB is already installed).
# See docker/entrypoint.sh for the full bootstrap flow.
RUN curl -fsSL -o /tmp/phpbb.tar.bz2 "${PHPBB_TARBALL_URL}" \
    && mkdir -p /usr/src/phpbb \
    && tar -xjf /tmp/phpbb.tar.bz2 -C /usr/src/phpbb --strip-components=1 --no-same-owner \
    && rm /tmp/phpbb.tar.bz2

# Stage the env-var driven config.php OUTSIDE the doc root. The
# entrypoint copies it to /var/www/html/config.php AFTER the CLI
# installer has run and written its own version — so the end state
# is the env-var driven file, not the installer's.
COPY config.php /etc/phpbb/config.php

# Stage the install-config.yml template. The entrypoint envsubst's
# it on first boot to produce the real install-config.yml at /tmp/.
COPY install-config.yml.template /etc/phpbb/install-config.yml.template

# Stage phpBB extensions. In dev, the entrypoint mounts ./extensions
# over this directory (see docker-compose.dev.yml), so code edits on
# the host take effect on restart. In prod, the code baked here ships
# in the image and the entrypoint copies it into /var/www/html/ext/.
# `extensions/.gitkeep` guarantees the COPY source always exists.
COPY extensions/ /etc/phpbb/extensions/

# Final ownership on the staging dir.
RUN chown -R www-data:www-data /usr/src/phpbb /etc/phpbb

# Custom entrypoint. Wraps the base image's docker-php-entrypoint
# (which is what actually starts Apache) so we can run our first-boot
# copy first. See docker/entrypoint.sh for the full reasoning.
COPY docker/entrypoint.sh /usr/local/bin/phpbb-entrypoint.sh
RUN chmod +x /usr/local/bin/phpbb-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/phpbb-entrypoint.sh"]
CMD ["apache2-foreground"]
