<?php

namespace App\Models\Concerns;

use App\Models\Rating;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** Tiket yang bisa diberi rating kepuasan sekali setelah selesai (surat, laporan). */
trait HasRating
{
    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }

    /** Memakai relasi yang sudah dimuat (daftar) bila ada, supaya tidak query per baris. */
    public function hasRating(): bool
    {
        return $this->relationLoaded('rating') ? $this->rating !== null : $this->rating()->exists();
    }
}
