FROM php:8.4-cli-alpine

RUN apk add --no-cache sqlite-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
# app/commands/ is a composer classmap path and must exist for install
RUN mkdir -p app/commands \
    && composer install --prefer-dist --no-dev --no-interaction --no-progress --no-scripts

COPY . .

RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["sh", "-c", "php runway migrate && php -S 0.0.0.0:8000 -t public"]
