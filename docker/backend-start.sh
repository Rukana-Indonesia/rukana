#!/bin/sh
# Migrasi database, isi data contoh hanya saat database masih kosong, lalu jalankan API.
set -e

php artisan migrate --force

if ! php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(App\Models\User::query()->exists() ? 0 : 1);'; then
    php artisan db:seed --force
    php artisan db:seed --class=ComplaintMapDemoSeeder --force
fi

exec php artisan serve --host=0.0.0.0 --port=8000
