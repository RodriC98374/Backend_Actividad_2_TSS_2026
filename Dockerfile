FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip python3 python3-venv libpq-dev libzip-dev libicu-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql mbstring zip intl gd pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY requirements-pdf.txt /tmp/requirements-pdf.txt
RUN python3 -m venv /opt/pdf && /opt/pdf/bin/pip install --no-cache-dir -r /tmp/requirements-pdf.txt
WORKDIR /var/www/html
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
