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

# ansible-lint lives in its own virtualenv. Installing it into the system
# interpreter (pip --break-system-packages) collides with the Debian-managed
# python3-cryptography that the apt `ansible` package depends on: pip tries
# to uninstall the Debian package and the image build fails. Inside a venv
# pip installs newer dependencies next to (shadowing) the system ones instead.
# --system-site-packages lets ansible-lint reuse the apt ansible-core, so it
# lints with the exact Ansible version that executes the playbooks and does
# not warn about a second `ansible` binary on PATH.
RUN apt-get update && apt-get install -y \
    curl \
    git \
    openssh-client \
    sudo \
    ansible \
    python3-pip \
    python3-venv \
    libzip-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv --system-site-packages /opt/ansible-lint \
    && /opt/ansible-lint/bin/pip install --no-cache-dir ansible-lint \
    && ln -s /opt/ansible-lint/bin/ansible-lint /usr/local/bin/ansible-lint

# Only the extensions the runner actually needs
RUN docker-php-ext-install pcntl zip

# Pre-install Ansible collections that Ansilume playbooks commonly
# rely on. community.general carries the `onepassword` / `hashi_vault`
# lookups, posix the `ansible.posix.*` modules — pulling them into the
# image means operators don't have to bake their own runner just to use
# a 1Password service-account token in an extra_var.
RUN ansible-galaxy collection install --force --ignore-certs \
        community.general \
        ansible.posix \
        community.crypto

# Application source
WORKDIR /var/www
COPY . /var/www/

# Composer dependencies from stage 1
COPY --from=composer /build/vendor /var/www/vendor

# Allow www-data to use sudo without a password (needed for ansible become).
RUN echo 'www-data ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/www-data \
    && chmod 0440 /etc/sudoers.d/www-data

# Runtime directories:
#   - /var/www/runtime         — token cache, project checkouts, artifacts
#   - /var/www/runtime/ansible-home — $HOME for ansible-playbook subprocesses
#     so lookup plugins that shell out to CLIs (op, hcloud, aws, …) can
#     write their ~/.config / ~/.cache without hitting the root-owned
#     /var/www. Matching buildProcessEnv() sets HOME to this dir.
#   - /var/www/.ansible        — ansible-compat's isolated cache dir
#   - /var/www/.cache          — ansible-lint's version-check cache (it is
#     created on `ansible-lint --version`; with the root-owned $HOME=/var/www
#     the command died with PermissionError when run as www-data)
RUN mkdir -p /var/www/runtime/ansible-home /var/www/.ansible/tmp /var/www/.cache \
    && chown -R www-data:www-data /var/www/runtime /var/www/.ansible /var/www/.cache

COPY docker/runner/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "yii", "runner/start"]
