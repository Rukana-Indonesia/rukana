<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'village_id', 'rw_id', 'period', 'metrics', 'narrative', 'status', 'model',
    'generated_by', 'generated_at',
])]
class MonthlyInsight extends Model
{
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'metrics' => 'array',
            // {ringkasan, sorotan[], perlu_perhatian[]}, atau {error} bila gagal.
            'narrative' => 'array',
            'status' => AiTaskStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
