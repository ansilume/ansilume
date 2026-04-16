FROM php:8.2-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libldap2-dev \
    zip \
    unzip \
    openssh-client \
    ansible \
    python3-pip \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && pip3 install --break-system-packages ansible-lint

RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    sockets \
    ldap

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
