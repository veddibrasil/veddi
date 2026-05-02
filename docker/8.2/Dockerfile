FROM ubuntu:24.04

LABEL maintainer="Taylor Otwell"

ARG WWWGROUP
ARG NODE_VERSION=20f
ARG POSTGRES_VERSION=16

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"
ENV SUPERVISOR_PHP_USER="sail"

# Timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Base packages
RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y \
    gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 \
    libcap2-bin libpng-dev python3 dnsutils librsvg2-bin fswatch ffmpeg nano \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP 8.3 (nativo do Ubuntu 24)
RUN apt-get update && \
    apt-get install -y \
    php-cli php-dev \
    php-pgsql php-sqlite3 php-gd php-imagick \
    php-curl php-mongodb \
    php-imap php-mysql php-mbstring \
    php-xml php-zip php-bcmath php-soap \
    php-intl php-readline \
    php-ldap \
    php-redis php-xdebug \
    php-gmp \
    && phpenmod gmp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -sLS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/bin/ --filename=composer

# Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    npm install -g npm pnpm bun && \
    corepack enable && \
    corepack prepare yarn@stable --activate

# PostgreSQL client
RUN apt-get update && \
    apt-get install -y postgresql-client-$POSTGRES_VERSION && \
    apt-get clean && rm -rf /var/lib/apt/lists/*


# Usuário sail
RUN userdel -r ubuntu || true
RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail

RUN git config --global --add safe.directory /var/www/html

# Arquivos do Sail
COPY vendor/laravel/sail/runtimes/8.2/start-container /usr/local/bin/start-container
COPY vendor/laravel/sail/runtimes/8.2/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY vendor/laravel/sail/runtimes/8.2/php.ini /etc/php/*/cli/conf.d/99-sail.ini

RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]