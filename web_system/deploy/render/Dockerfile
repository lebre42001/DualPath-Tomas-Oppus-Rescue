FROM php:8.3-fpm-alpine AS php-base

ENV APP_ENV=production \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN apk add --no-cache \
    bash \
    fcgi \
    freetype-dev \
    gettext \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    linux-headers \
    nginx \
    oniguruma-dev \
    postgresql-dev \
    shadow \
    sqlite-dev \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    bcmath \
    exif \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    pdo_mysql \
    pdo_sqlite \
    sockets \
    zip


# Install dependencies on the same PHP platform used by the running app.
FROM php-base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip

WORKDIR /app

COPY . .

RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && composer check-platform-reqs --no-dev


FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN npm run build


FROM php-base AS runtime

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY deploy/render/php.ini /usr/local/etc/php/conf.d/render.ini
COPY deploy/render/nginx.default.conf.template /etc/nginx/templates/default.conf.template
COPY deploy/render/start-web.sh /usr/local/bin/start-web

RUN chmod +x /usr/local/bin/start-web \
    && mkdir -p /run/nginx /var/lib/nginx/tmp/client_body /var/log/nginx \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 10000

CMD ["/usr/local/bin/start-web"]
