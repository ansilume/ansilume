# =============================================================================
# Standalone runner image
#
# Minimal PHP CLI image with Ansible. Communicates with the ansilume server
# exclusively via HTTP API — no database or Redis dependencies.
#
# Can be deployed independently in any network that has HTTP access to the
# ansilume server.
#
# Used by: ghcr.io/ansilume/ansilume-runner
# =============================================================================

# -- Stage 1: Composer dependencies -------------------------------------------
FROM composer:2.6 AS composer

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader --prefer-dist

# -- Stage 2: Runtime ---------------------------------------------------------
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    curl \
    openssh-client \
    ansible \
    python3-pip \
    libzip-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && pip3 install --break-system-packages ansible-lint

# Only the extensions the runner actually needs
RUN docker-php-ext-install pcntl zip

# Application source
WORKDIR /var/www
COPY . /var/www/

# Composer dependencies from stage 1
COPY --from=composer /build/vendor /var/www/vendor

# Runtime directories: token cache + Ansible tmp (www-data home is /var/www)
RUN mkdir -p /var/www/runtime /var/www/.ansible/tmp \
    && chown -R www-data:www-data /var/www/runtime /var/www/.ansible

COPY docker/runner/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "yii", "runner/start"]
