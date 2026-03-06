FROM php:8.2-fpm-alpine AS base

# Dependências do sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    nodejs \
    npm \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    sqlite \
    sqlite-dev \
    pcre-dev \
    $PHPIZE_DEPS

# Extensões PHP
RUN docker-php-ext-install pdo pdo_sqlite pcntl bcmath \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd

# Instala phpredis
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ─── Dependências PHP ────────────────────────────────────────────────────────
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# ─── Assets frontend ─────────────────────────────────────────────────────────
FROM base AS assets
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

# ─── Imagem final ─────────────────────────────────────────────────────────────
FROM base AS final

COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build
COPY . .

# Scripts pós-install do composer (sem vendor duplicado)
RUN composer dump-autoload --optimize --no-dev

# Configs de produção
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Permissões
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# SQLite: garante que o arquivo existe e é acessível
RUN mkdir -p database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data database

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
