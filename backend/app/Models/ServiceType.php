<?php

namespace App\Models;

use App\Enums\LetterFlow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'village_id', 'slug', 'name', 'purpose', 'requirements', 'flow', 'estimated_days',
    'notes', 'form_schema', 'requires_nik', 'is_active', 'sort_order',
])]
class ServiceType extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'form_schema' => 'array',
            'flow' => LetterFlow::class,
            'requires_nik' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function letterRequests(): HasMany
    {
        return $this->hasMany(LetterRequest::class);
    }
}
