<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['village_id', 'number', 'name'])]
class Rw extends Model
{
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function rts(): HasMany
    {
        return $this->hasMany(Rt::class)->orderBy('number');
    }

    /** Mis. "RW 03". */
    public function label(): string
    {
        return 'RW '.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
