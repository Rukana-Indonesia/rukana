# Image API Laravel untuk docker-compose.dev.yml (engine Sokudo).
# Engine Sokudo hanya membagikan /Users ke VM-nya, sehingga folder proyek di
# drive eksternal tidak bisa di-bind-mount: kode disalin ke image saat build.
# vendor/ dari host ikut disalin (termasuk paket dev seperti Faker untuk seeder).
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# 5 lampiran x 5 MB per pengajuan surat.
RUN printf 'upload_max_filesize=10M\npost_max_size=30M\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/rukana.ini

WORKDIR /app
COPY . .
COPY --from=docker backend-start.sh /usr/local/bin/rukana-api-start
RUN chmod +x /usr/local/bin/rukana-api-start \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

EXPOSE 8000
