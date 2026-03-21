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
    zip \
    unzip \
    openssh-client \
    ansible \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    sockets

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

RUN groupmod -g ${GROUP_ID} www-data && usermod -u ${USER_ID} -g ${GROUP_ID} www-data

COPY --chown=root:root docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
