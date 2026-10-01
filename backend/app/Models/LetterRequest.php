<?php

namespace App\Models;

use App\Enums\LetterFlow;
use App\Enums\LetterStatus;
use App\Enums\Level;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'code', 'user_id', 'service_type_id', 'flow', 'village_id', 'rw_id', 'rt_id', 'purpose',
    'form_data', 'nik_encrypted', 'nik_masked', 'status', 'current_level', 'rejected_level',
    'rejection_reason', 'submitted_at', 'completed_at', 'idempotency_key',
])]
#[Hidden(['nik_encrypted'])]
class LetterRequest extends Model
{
    protected function casts(): array
    {
        return [
            'flow' => LetterFlow::class,
            'status' => LetterStatus::class,
            'current_level' => Level::class,
            'rejected_level' => Level::class,
            'form_data' => 'array',
            'nik_encrypted' => 'encrypted',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class)->withTrashed();
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function rt(): BelongsTo
    {
        return $this->belongsTo(Rt::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LetterStep::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LetterAttachment::class);
    }

    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }
}
