FROM node:24-alpine AS assets
WORKDIR /build
COPY package*.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js .
RUN npm run build

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip opcache \
    && pecl install redis-6.2.0 && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=assets /build/public/build public/build
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php.ini /usr/local/etc/php/conf.d/tollgate.ini
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
