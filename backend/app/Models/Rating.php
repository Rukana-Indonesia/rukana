<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Rating kepuasan 1–5 untuk surat atau laporan yang selesai (PRD 6.7). Wilayah,
 * layanan, dan kategori disalin dari tiketnya supaya rekap per wilayah tidak perlu
 * join ke tiket atau akun.
 */
#[Fillable([
    'rateable_type', 'rateable_id', 'user_id', 'score', 'comment', 'village_id', 'rw_id',
    'rt_id', 'service_type_id', 'category_id',
])]
class Rating extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function rt(): BelongsTo
    {
        return $this->belongsTo(Rt::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class);
    }
}
