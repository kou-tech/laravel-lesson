FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git curl unzip procps \
    libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install mbstring zip pcntl pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]

CMD ["npx", "concurrently", \
    "-c", "#93c5fd,#c4b5fd,#fb7185,#fdba74", \
    "php artisan serve --host=0.0.0.0", \
    "php artisan queue:listen --tries=1", \
    "php artisan pail --timeout=0", \
    "npm run dev -- --host", \
    "--names=server,queue,logs,vite", \
    "--kill-others"]
