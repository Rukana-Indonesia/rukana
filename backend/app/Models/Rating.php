<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'rateable_type', 'rateable_id', 'user_id', 'score', 'comment', 'village_id', 'rw_id',
    'rt_id', 'service_type_id', 'category_id',
])]
class Rating extends Model
{
    public const UPDATED_AT = null;

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
