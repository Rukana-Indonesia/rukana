<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * AI tidak bisa dipakai saat ini: kunci API belum diisi, layanan gagal, atau model
 * menolak permintaan. Pesannya aman ditampilkan ke pengurus.
 */
class AiUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Fitur AI belum dikonfigurasi. Isi ANTHROPIC_API_KEY di backend/.env.');
    }
}
