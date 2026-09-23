<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'code', 'reporter_id', 'village_id', 'rw_id', 'rt_id', 'title', 'description',
    'reported_category_id', 'category_id', 'urgency', 'handling_level', 'status', 'latitude',
    'longitude', 'location_text', 'occurred_at', 'rejection_reason', 'verified_at',
    'resolved_at', 'idempotency_key',
])]
class Complaint extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ComplaintStatus::class,
            'handling_level' => Level::class,
            'urgency' => Urgency::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'occurred_at' => 'datetime',
            'verified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class);
    }

    public function reportedCategory(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'reported_category_id');
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function rt(): BelongsTo
    {
        return $this->belongsTo(Rt::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ComplaintPhoto::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ComplaintStep::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ComplaintComment::class)->orderBy('id');
    }

    public function aiLabel(): HasOne
    {
        return $this->hasOne(ComplaintAiLabel::class);
    }

    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }
}
