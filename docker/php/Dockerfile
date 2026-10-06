FROM php:8.2-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000

# ansible-lint lives in its own virtualenv. Installing it into the system
# interpreter (pip --break-system-packages) collides with the Debian-managed
# python3-cryptography that the apt `ansible` package depends on: pip tries
# to uninstall the Debian package and the image build fails. Inside a venv
# pip installs newer dependencies next to (shadowing) the system ones instead.
# --system-site-packages lets ansible-lint reuse the apt ansible-core, so it
# lints with the exact Ansible version that executes the playbooks and does
# not warn about a second `ansible` binary on PATH.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libldap2-dev \
    libffi-dev \
    zip \
    unzip \
    openssh-client \
    ansible \
    python3-pip \
    python3-venv \
    gosu \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv --system-site-packages /opt/ansible-lint \
    && /opt/ansible-lint/bin/pip install --no-cache-dir ansible-lint \
    && ln -s /opt/ansible-lint/bin/ansible-lint /usr/local/bin/ansible-lint

# Pre-install Ansible collections that Ansilume playbooks commonly
# rely on (community.general provides lookups like `onepassword` and
# `hashi_vault`; ansible.posix covers the core `posix.*` modules).
RUN ansible-galaxy collection install --force --ignore-certs \
        community.general \
        ansible.posix \
        community.crypto

# ffi: lets console workers mark themselves non-dumpable (prctl), so child
# processes running repository code cannot read the worker's environment
# through /proc. See components/ProcessHardening.php.
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    sockets \
    ldap \
    ffi

RUN pecl install redis && docker-php-ext-enable redis

RUN pecl install pcov && docker-php-ext-enable pcov

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

RUN if [ "${USER_ID}" != "0" ]; then \
        groupmod -g ${GROUP_ID} www-data && \
        usermod -u ${USER_ID} -g ${GROUP_ID} www-data; \
    fi

COPY --chown=root:root docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV ANSIBLE_HOME=/tmp/ansible

WORKDIR /var/www

# Run entrypoint as root so composer install can write vendor/ to the
# volume-mounted source tree regardless of host UID. The entrypoint
# drops back to www-data via php-fpm's own process model for the
# actual application (php-fpm handles user switching via its pool config).
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
