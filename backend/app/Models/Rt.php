<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rw_id', 'village_id', 'number'])]
class Rt extends Model
{
    protected static function booted(): void
    {
        // village_id adalah denormalisasi dari RW induk; selalu ikut RW.
        static::saving(function (Rt $rt) {
            $rt->village_id = Rw::whereKey($rt->rw_id)->value('village_id');
        });
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    /** Mis. "RT 02". */
    public function label(): string
    {
        return 'RT '.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
