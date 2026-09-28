<?php

namespace App\Support;

class TemporaryPassword
{
    /** Tanpa huruf/angka yang mudah tertukar (l/1, o/0, i) supaya bisa didiktekan lewat telepon. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Kata sandi sementara 8 karakter; pemiliknya wajib menggantinya saat masuk. */
    public static function generate(int $length = 8): string
    {
        $max = strlen(self::ALPHABET) - 1;

        return implode('', array_map(fn () => self::ALPHABET[random_int(0, $max)], range(1, $length)));
    }
}
