FROM php:8.2-cli

# Install unzip and PostgreSQL dev libraries
RUN apt-get update && apt-get install -y unzip libpq-dev

# Install pdo_pgsql extension
RUN docker-php-ext-install pdo pdo_pgsql

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]